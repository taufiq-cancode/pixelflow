<?php

namespace Tests\Feature\Console;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\GenerationCost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\Pixelforge;
use Tests\TestCase;

class ReconcileGenerationsTest extends TestCase
{
    use RefreshDatabase;

    private function quietGeneration(string $status = 'processing'): Generation
    {
        $generation = Generation::factory()->submitted($status)->create();
        $generation->forceFill(['last_synced_at' => Date::now()->subMinutes(5)])->save();

        return $generation;
    }

    public function test_a_lost_success_webhook_is_recovered_by_polling()
    {
        $generation = $this->quietGeneration();
        Http::fake(["pixelforge.test/v1/generations/{$generation->pixelforge_id}" => Http::response(
            Pixelforge::generation((string) $generation->pixelforge_id, 'succeeded'),
        )]);

        $this->artisan('pixelflow:reconcile-generations')->assertSuccessful();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(Pixelforge::USER_COST_NANO, GenerationCost::sole()->user_cost_nano);
    }

    public function test_polling_and_a_late_webhook_still_charge_once()
    {
        $generation = $this->quietGeneration();
        $succeeded = Pixelforge::generation((string) $generation->pixelforge_id, 'succeeded');
        Http::fake(["pixelforge.test/v1/generations/{$generation->pixelforge_id}" => Http::response($succeeded)]);

        $this->artisan('pixelflow:reconcile-generations')->assertSuccessful();

        $payload = (string) json_encode(Pixelforge::event('evt_late', 'generation.succeeded', $succeeded));
        $this->call('POST', '/webhooks/pixelforge', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => Pixelforge::signature($payload),
        ], content: $payload)->assertOk()->assertJson(['outcome' => 'ignored_stale']);

        $this->assertDatabaseCount('generation_costs', 1);
    }

    public function test_a_lost_failure_webhook_is_recovered_at_no_cost()
    {
        $generation = $this->quietGeneration();
        Http::fake(["pixelforge.test/v1/generations/{$generation->pixelforge_id}" => Http::response(
            Pixelforge::generation((string) $generation->pixelforge_id, 'failed'),
        )]);

        $this->artisan('pixelflow:reconcile-generations')->assertSuccessful();

        $this->assertSame(GenerationStatus::Failed, $generation->fresh()?->status);
        $this->assertDatabaseCount('generation_costs', 0);
    }

    public function test_recently_updated_and_finished_generations_are_not_polled()
    {
        Generation::factory()->submitted('processing')->create(); // synced just now
        $finished = $this->quietGeneration();
        $finished->forceFill(['status' => GenerationStatus::Succeeded])->save();
        Generation::factory()->create(); // not submitted yet: no Pixelforge id to poll

        Http::fake();

        $this->artisan('pixelflow:reconcile-generations')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_polling_stops_when_rate_limited()
    {
        Sleep::fake();
        $this->quietGeneration();
        $this->quietGeneration();
        Http::fake(['pixelforge.test/v1/generations/*' => Http::response(
            Pixelforge::error('rate_limit_exceeded', 'rate_limit_error', 'Slow down.'), 429, ['Retry-After' => '1'],
        )]);

        $this->artisan('pixelflow:reconcile-generations')->assertSuccessful();

        // Three attempts (inline retries) for the first generation, then stop.
        Http::assertSentCount(3);
    }

    public function test_an_orphaned_submission_is_queued_again()
    {
        Queue::fake();
        $orphan = Generation::factory()->create();
        $orphan->forceFill(['updated_at' => Date::now()->subMinutes(20)])->saveQuietly();
        Generation::factory()->create(); // fresh: its job is still running

        $this->artisan('pixelflow:reconcile-generations')->assertSuccessful();

        Queue::assertPushed(SubmitGenerationJob::class, 1);
        Queue::assertPushed(SubmitGenerationJob::class, fn (SubmitGenerationJob $job) => $job->generationId === $orphan->id);
    }
}
