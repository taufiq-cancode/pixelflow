<?php

namespace Tests\Feature\Credits;

use App\Actions\ApplyGenerationSnapshot;
use App\Actions\PurchaseCredits;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Services\CreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Pixelforge;
use Tests\TestCase;

class CreditBalanceTest extends TestCase
{
    use RefreshDatabase;

    private const DOLLAR = 1_000_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake(['pixelforge.test/v1/pricing' => Http::response(Pixelforge::pricing())]);
    }

    private function ledger(): CreditLedger
    {
        return app(CreditLedger::class);
    }

    /**
     * Docs' worked example: up to $0.46734375 for the user.
     *
     * @return array<string, mixed>
     */
    private function expensiveInput(): array
    {
        return [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
        ];
    }

    public function test_buying_credits_adds_exact_dollars()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('credits.store'), ['amount' => 10])->assertRedirect();

        $this->assertSame(10 * self::DOLLAR, $this->ledger()->balanceNano($user));
    }

    public function test_only_the_offered_packs_can_be_bought()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('credits.store'), ['amount' => 1000])->assertSessionHasErrors('amount');
        $this->actingAs($user)->post(route('credits.store'), ['amount' => -5])->assertSessionHasErrors('amount');

        $this->assertSame(0, $this->ledger()->balanceNano($user));
    }

    public function test_generating_is_blocked_when_the_user_cannot_afford_the_maximum_estimate()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('generations.store'), $this->expensiveInput())
            ->assertSessionHasErrors(['credits' => 'This generation can cost up to $0.467343750, but you have $0.000000000 available. Buy credits to continue.']);

        $this->assertDatabaseCount('generations', 0);
        Queue::assertNothingPushed();
    }

    public function test_unfinished_generations_reserve_their_maximum_estimate()
    {
        $user = User::factory()->create();
        app(PurchaseCredits::class)->handle($user, 5);

        $this->actingAs($user)->post(route('generations.store'), $this->expensiveInput())->assertSessionHasNoErrors();

        $this->assertSame(5 * self::DOLLAR, $this->ledger()->balanceNano($user));
        $this->assertSame(467_343_750, $this->ledger()->reservedNano($user));
        $this->assertSame(5 * self::DOLLAR - 467_343_750, $this->ledger()->availableNano($user));
    }

    public function test_the_reservation_stops_a_user_from_overspending()
    {
        // $5 covers 10 generations reserved at $0.46734375, not 11.
        $user = User::factory()->create();
        app(PurchaseCredits::class)->handle($user, 5);

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->post(route('generations.store'), $this->expensiveInput())->assertSessionHasNoErrors();
        }

        $this->actingAs($user)->post(route('generations.store'), $this->expensiveInput())->assertSessionHasErrors('credits');

        $this->assertDatabaseCount('generations', 10);
    }

    public function test_success_charges_the_actual_cost_and_releases_the_reservation()
    {
        $user = User::factory()->create();
        app(PurchaseCredits::class)->handle($user, 5);
        $generation = Generation::factory()->for($user)->submitted('processing')->create(['estimated_max_cost_nano' => 100_000_000]);

        app(ApplyGenerationSnapshot::class)->handle($generation->id, Pixelforge::generation((string) $generation->pixelforge_id, 'succeeded'));

        $this->assertSame(5 * self::DOLLAR - Pixelforge::USER_COST_NANO, $this->ledger()->balanceNano($user));
        $this->assertSame(0, $this->ledger()->reservedNano($user));
    }

    public function test_failure_releases_the_reservation_and_charges_nothing()
    {
        $user = User::factory()->create();
        app(PurchaseCredits::class)->handle($user, 5);
        $generation = Generation::factory()->for($user)->submitted('processing')->create(['estimated_max_cost_nano' => 100_000_000]);
        $this->assertSame(100_000_000, $this->ledger()->reservedNano($user));

        app(ApplyGenerationSnapshot::class)->handle($generation->id, Pixelforge::generation((string) $generation->pixelforge_id, 'failed'));

        $this->assertSame(GenerationStatus::Failed, $generation->fresh()?->status);
        $this->assertSame(5 * self::DOLLAR, $this->ledger()->balanceNano($user));
        $this->assertSame(0, $this->ledger()->reservedNano($user));
    }

    public function test_balances_are_shared_with_every_page()
    {
        $user = User::factory()->create();
        app(PurchaseCredits::class)->handle($user, 25);

        $this->actingAs($user)
            ->get(route('credits.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('credits/Index')
                ->where('credits.balance_usd', '25.000000000')
                ->where('credits.available_usd', '25.000000000')
                ->where('packs', [5, 10, 25])
                ->has('purchases', 1));
    }
}
