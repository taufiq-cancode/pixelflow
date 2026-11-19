<?php

namespace Tests\Feature\Admin;

use App\Models\Generation;
use App\Models\GenerationCost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UsageReportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A billed generation for a user on a UTC day.
     */
    private function cost(User $user, string $day, int $provider, int $charged, int $output = 1100): void
    {
        $generation = Generation::factory()->for($user)->submitted('succeeded')->create();

        GenerationCost::create([
            'generation_id' => $generation->id,
            'input_text_tokens' => 10,
            'input_image_tokens' => 0,
            'output_tokens' => $output,
            'reasoning_tokens' => 500,
            'provider_cost_nano' => $provider,
            'user_cost_nano' => $charged,
            'markup_bps' => 2500,
            'user_charged' => true,
            'billed_on' => $day,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $days
     */
    private function fakeUsage(array $days): void
    {
        Http::fake(['pixelforge.test/v1/usage*' => Http::response([
            'object' => 'usage',
            'data' => $days,
            'total' => [],
        ])]);
    }

    /**
     * @return array<string, mixed>
     */
    private function usageDay(string $date, int $generations, string $cost, int $text, int $output, int $reasoning): array
    {
        return [
            'date' => $date,
            'generations' => $generations,
            'input_text_tokens' => $text,
            'input_image_tokens' => 0,
            'output_tokens' => $output,
            'reasoning_tokens' => $reasoning,
            'cost_usd' => $cost,
        ];
    }

    public function test_only_admins_can_see_the_report()
    {
        $this->fakeUsage([]);

        $this->get(route('admin.usage'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.usage'))->assertForbidden();
    }

    public function test_it_reports_spend_cost_and_profit_per_day_and_user_and_reconciles_with_pixelforge()
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $admin->forceFill(['is_admin' => true])->save();
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);

        $this->cost($alice, '2026-11-03', 62_810_000, 78_512_500);
        $this->cost($bob, '2026-11-03', 49_077_500, 61_346_875);
        $this->cost($alice, '2026-11-04', 66_592_500, 83_240_625);

        $this->fakeUsage([
            $this->usageDay('2026-11-03', 2, '0.111887500', 20, 2200, 1000),
            $this->usageDay('2026-11-04', 1, '0.066592500', 10, 1100, 500),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.usage', ['start' => '2026-11-03', 'end' => '2026-11-04']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/Usage')
                ->where('report.reconciled', true)
                ->where('report.pixelforge_error', null)
                ->has('report.days', 2)
                ->where('report.days.0.date', '2026-11-04') // newest first
                ->where('report.days.1.date', '2026-11-03')
                ->where('report.days.1.generations', 2)
                ->where('report.days.1.charged_usd', '0.139859375')
                ->where('report.days.1.provider_usd', '0.111887500')
                ->where('report.days.1.profit_usd', '0.027971875')
                ->where('report.days.1.pixelforge.cost_usd', '0.111887500')
                ->where('report.days.1.matches', true)
                ->has('report.users', 3)
                ->where('report.totals.provider_usd', '0.178480000')
                ->where('report.totals.charged_usd', '0.223100000')
                ->where('report.totals.profit_usd', '0.044620000')
                ->where('report.totals.pixelforge_cost_usd', '0.178480000'));
    }

    public function test_a_day_that_differs_from_pixelforge_is_flagged()
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->cost($admin, '2026-11-04', 66_592_500, 83_240_625);

        // Pixelforge billed one nanodollar more (e.g. a generation we missed).
        $this->fakeUsage([$this->usageDay('2026-11-04', 1, '0.066592501', 10, 1100, 500)]);

        $this->actingAs($admin)
            ->get(route('admin.usage', ['start' => '2026-11-04', 'end' => '2026-11-04']))
            ->assertInertia(fn ($page) => $page
                ->where('report.reconciled', false)
                ->where('report.days.0.matches', false));
    }

    public function test_the_report_still_works_when_pixelforge_is_unreachable()
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->cost($admin, '2026-11-04', 66_592_500, 83_240_625);
        Http::fake(['pixelforge.test/v1/usage*' => Http::failedConnection()]);

        $this->actingAs($admin)
            ->get(route('admin.usage', ['start' => '2026-11-04', 'end' => '2026-11-04']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('report.reconciled', null)
                ->where('report.days.0.provider_usd', '0.066592500')
                ->where('report.days.0.pixelforge', null));
    }

    public function test_the_range_is_limited_to_92_days()
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)
            ->get(route('admin.usage', ['start' => '2026-01-01', 'end' => '2026-11-04']))
            ->assertSessionHasErrors('end');
    }

    public function test_the_audit_command_passes_when_every_day_matches()
    {
        $user = User::factory()->create();
        $this->cost($user, '2026-11-04', 66_592_500, 83_240_625);
        $this->fakeUsage([$this->usageDay('2026-11-04', 1, '0.066592500', 10, 1100, 500)]);

        $this->artisan('pixelflow:audit-usage', ['--start' => '2026-11-04', '--end' => '2026-11-04'])
            ->expectsOutputToContain('Every day matches Pixelforge exactly.')
            ->assertSuccessful();
    }

    public function test_the_audit_command_fails_on_a_mismatch()
    {
        $user = User::factory()->create();
        $this->cost($user, '2026-11-04', 66_592_500, 83_240_625);
        $this->fakeUsage([$this->usageDay('2026-11-04', 2, '0.115670000', 28, 2200, 899)]);

        $this->artisan('pixelflow:audit-usage', ['--start' => '2026-11-04', '--end' => '2026-11-04'])
            ->expectsOutputToContain('Some days do not match Pixelforge exactly.')
            ->assertFailed();
    }

    public function test_make_admin_grants_access()
    {
        $user = User::factory()->create(['email' => 'ops@example.com']);

        $this->artisan('pixelflow:make-admin', ['email' => 'ops@example.com'])->assertSuccessful();

        $this->assertTrue($user->fresh()?->is_admin);
    }
}
