<?php

namespace Tests\Feature\Webhooks;

use App\Actions\FailUnsubmittedGeneration;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\GenerationCost;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use Tests\Support\Pixelforge;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sends a webhook with its raw body signed like Pixelforge would.
     *
     * @param  array<string, mixed>  $event
     */
    private function sendWebhook(array $event, ?string $signature = null, ?string $rawBody = null): TestResponse
    {
        $payload = $rawBody ?? (string) json_encode($event);

        return $this->call('POST', '/webhooks/pixelforge', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_EVENT_ID' => (string) ($event['id'] ?? ''),
            'HTTP_PIXELFORGE_EVENT_TYPE' => (string) ($event['type'] ?? ''),
            'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
            'HTTP_PIXELFORGE_SIGNATURE' => $signature ?? Pixelforge::signature($payload),
        ], content: $payload);
    }

    private function processingGeneration(): Generation
    {
        return Generation::factory()->submitted('processing')->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function succeededEvent(Generation $generation, string $eventId = 'evt_succeeded1', array $overrides = []): array
    {
        return Pixelforge::event($eventId, 'generation.succeeded', Pixelforge::generation((string) $generation->pixelforge_id, 'succeeded', $overrides));
    }

    // ---------------------------------------------------------------
    // Signature verification
    // ---------------------------------------------------------------

    public function test_a_correctly_signed_ping_is_accepted()
    {
        $this->sendWebhook(['id' => 'evt_ping1', 'object' => 'event', 'type' => 'ping', 'data' => []])
            ->assertOk()
            ->assertJson(['received' => true, 'outcome' => 'ping']);

        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_ping1', 'outcome' => 'ping', 'delivery_attempt' => 1]);
    }

    public function test_a_signature_made_with_another_secret_is_rejected()
    {
        $generation = $this->processingGeneration();
        $event = $this->succeededEvent($generation);
        $payload = (string) json_encode($event);

        $this->sendWebhook($event, Pixelforge::signature($payload, 'whsec_someone_else'))
            ->assertStatus(400);

        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('generation_costs', 0);
        $this->assertSame(GenerationStatus::Processing, $generation->fresh()?->status);
    }

    public function test_a_tampered_body_is_rejected()
    {
        $generation = $this->processingGeneration();
        $original = (string) json_encode($this->succeededEvent($generation));
        $signature = Pixelforge::signature($original);

        // An attacker inflates the usage but keeps the original signature.
        $tampered = str_replace('"output_tokens":1100', '"output_tokens":999999', $original);
        $this->assertNotSame($original, $tampered);

        $this->sendWebhook([], $signature, $tampered)->assertStatus(400);

        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('generation_costs', 0);
    }

    public function test_a_missing_signature_is_rejected()
    {
        $this->sendWebhook(['id' => 'evt_ping1', 'type' => 'ping'], signature: '')->assertStatus(400);

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_a_malformed_signature_header_is_rejected()
    {
        foreach (['garbage', 't=abc,v1=def', 'v1=deadbeef', 't=1762265009'] as $header) {
            $this->sendWebhook(['id' => 'evt_ping1', 'type' => 'ping'], signature: $header)->assertStatus(400);
        }

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_an_old_timestamp_is_rejected_to_stop_replays()
    {
        $event = ['id' => 'evt_ping1', 'type' => 'ping'];
        $payload = (string) json_encode($event);

        $this->sendWebhook($event, Pixelforge::signature($payload, timestamp: Date::now()->getTimestamp() - 301), $payload)
            ->assertStatus(400);

        $this->sendWebhook($event, Pixelforge::signature($payload, timestamp: Date::now()->getTimestamp() - 299), $payload)
            ->assertOk();
    }

    public function test_a_signed_but_malformed_event_is_rejected()
    {
        $this->sendWebhook([], rawBody: 'not json')->assertStatus(400);
        $this->sendWebhook(['id' => 'not-an-event-id', 'type' => 'ping'])->assertStatus(400);
        $this->sendWebhook(['id' => 'evt_1', 'type' => 'generation.succeeded', 'data' => []])->assertStatus(400);

        $this->assertDatabaseCount('webhook_events', 0);
    }

    // ---------------------------------------------------------------
    // Applying events
    // ---------------------------------------------------------------

    public function test_a_succeeded_event_records_the_exact_cost_once()
    {
        $generation = $this->processingGeneration();

        $this->sendWebhook($this->succeededEvent($generation))
            ->assertOk()
            ->assertJson(['outcome' => 'applied']);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertStringStartsWith('https://pixelforge.test/images/', (string) $generation->image_url);
        $this->assertSame('2026-11-04 14:03:29', $generation->completed_at?->format('Y-m-d H:i:s'));

        $cost = GenerationCost::sole();
        $this->assertSame($generation->id, $cost->generation_id);
        $this->assertSame(Pixelforge::PROVIDER_COST_NANO, $cost->provider_cost_nano);
        $this->assertSame(Pixelforge::USER_COST_NANO, $cost->user_cost_nano);
        $this->assertSame(2500, $cost->markup_bps);
        $this->assertTrue($cost->user_charged);
        $this->assertSame(1100, $cost->output_tokens);
        $this->assertSame(1500, $cost->reasoning_tokens);
        // Pixelforge bills on the UTC day the generation completed.
        $this->assertSame('2026-11-04', $cost->billed_on->toDateString());
    }

    public function test_an_event_delivered_twice_is_recorded_once()
    {
        $generation = $this->processingGeneration();
        $event = $this->succeededEvent($generation);

        $this->sendWebhook($event)->assertOk()->assertJson(['outcome' => 'applied']);
        $this->sendWebhook($event)->assertOk()->assertJson(['outcome' => 'duplicate']);

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseCount('generation_costs', 1);
        $this->assertSame(Pixelforge::USER_COST_NANO, GenerationCost::sole()->user_cost_nano);
    }

    public function test_two_different_success_events_for_one_generation_charge_once()
    {
        $generation = $this->processingGeneration();

        $this->sendWebhook($this->succeededEvent($generation, 'evt_first'))->assertJson(['outcome' => 'applied']);
        $this->sendWebhook($this->succeededEvent($generation, 'evt_second'))->assertJson(['outcome' => 'ignored_stale']);

        $this->assertDatabaseCount('webhook_events', 2);
        $this->assertDatabaseCount('generation_costs', 1);
    }

    public function test_a_late_processing_event_never_moves_a_succeeded_generation_back()
    {
        $generation = $this->processingGeneration();
        $pixelforgeId = (string) $generation->pixelforge_id;

        $this->sendWebhook($this->succeededEvent($generation))->assertJson(['outcome' => 'applied']);
        $this->sendWebhook(Pixelforge::event('evt_processing', 'generation.processing', Pixelforge::generation($pixelforgeId, 'processing')))
            ->assertOk()
            ->assertJson(['outcome' => 'ignored_stale']);

        $this->assertSame(GenerationStatus::Succeeded, $generation->fresh()?->status);
        $this->assertDatabaseCount('generation_costs', 1);
    }

    public function test_completed_can_arrive_before_processing()
    {
        $generation = Generation::factory()->submitted('queued')->create();
        $pixelforgeId = (string) $generation->pixelforge_id;

        $this->sendWebhook($this->succeededEvent($generation))->assertJson(['outcome' => 'applied']);
        $this->assertSame(GenerationStatus::Succeeded, $generation->fresh()?->status);

        $this->sendWebhook(Pixelforge::event('evt_processing', 'generation.processing', Pixelforge::generation($pixelforgeId, 'processing')))
            ->assertJson(['outcome' => 'ignored_stale']);

        $this->assertSame(GenerationStatus::Succeeded, $generation->fresh()?->status);
        $this->assertDatabaseCount('generation_costs', 1);
    }

    public function test_a_failed_generation_costs_nothing()
    {
        $generation = $this->processingGeneration();
        $failed = Pixelforge::generation((string) $generation->pixelforge_id, 'failed');

        $this->sendWebhook(Pixelforge::event('evt_failed', 'generation.failed', $failed))
            ->assertOk()
            ->assertJson(['outcome' => 'applied']);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('internal_error', $generation->error_code);
        $this->assertStringContainsString('You were not charged', (string) $generation->error_message);
        $this->assertDatabaseCount('generation_costs', 0);
    }

    public function test_a_failed_generation_is_never_overwritten_by_a_later_event()
    {
        $generation = $this->processingGeneration();
        $pixelforgeId = (string) $generation->pixelforge_id;

        $this->sendWebhook(Pixelforge::event('evt_failed', 'generation.failed', Pixelforge::generation($pixelforgeId, 'failed')));
        $this->sendWebhook(Pixelforge::event('evt_processing', 'generation.processing', Pixelforge::generation($pixelforgeId, 'processing')))
            ->assertJson(['outcome' => 'ignored_stale']);

        $this->assertSame(GenerationStatus::Failed, $generation->fresh()?->status);
    }

    public function test_a_webhook_that_beats_the_submit_response_is_matched_by_metadata()
    {
        // Our row exists, but the POST /generations response hasn't come back,
        // so the Pixelforge id isn't linked yet.
        $generation = Generation::factory()->create();
        $snapshot = Pixelforge::generation('gen_EARLY', 'processing', [
            'metadata' => ['pixelflow_generation_id' => (string) $generation->id],
        ]);

        $this->sendWebhook(Pixelforge::event('evt_early', 'generation.processing', $snapshot))
            ->assertOk()
            ->assertJson(['outcome' => 'applied']);

        $generation->refresh();
        $this->assertSame('gen_EARLY', $generation->pixelforge_id);
        $this->assertSame(GenerationStatus::Processing, $generation->status);
    }

    public function test_an_event_for_an_unknown_generation_is_acknowledged()
    {
        $event = Pixelforge::event('evt_unknown', 'generation.succeeded', Pixelforge::generation('gen_NOT_OURS', 'succeeded'));

        $this->sendWebhook($event)->assertOk()->assertJson(['outcome' => 'unmatched']);

        $this->assertDatabaseCount('generation_costs', 0);
    }

    public function test_metadata_cannot_claim_a_generation_already_linked_to_another_pixelforge_id()
    {
        $generation = $this->processingGeneration();
        $snapshot = Pixelforge::generation('gen_IMPOSTOR', 'succeeded', [
            'metadata' => ['pixelflow_generation_id' => (string) $generation->id],
        ]);

        $this->sendWebhook(Pixelforge::event('evt_impostor', 'generation.succeeded', $snapshot))
            ->assertJson(['outcome' => 'unmatched']);

        $this->assertSame(GenerationStatus::Processing, $generation->fresh()?->status);
        $this->assertDatabaseCount('generation_costs', 0);
    }

    public function test_a_processing_error_rolls_back_so_the_retry_is_processed()
    {
        $generation = $this->processingGeneration();

        // A succeeded event without usage can't be priced: the handler throws.
        $broken = $this->succeededEvent($generation, 'evt_retry', ['usage' => null]);
        $this->sendWebhook($broken)->assertStatus(500);

        // Nothing was kept, not even the event claim, so Pixelforge's retry
        // isn't mistaken for a duplicate.
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertSame(GenerationStatus::Processing, $generation->fresh()?->status);

        $this->sendWebhook($this->succeededEvent($generation, 'evt_retry'))
            ->assertOk()
            ->assertJson(['outcome' => 'applied']);

        $this->assertDatabaseCount('generation_costs', 1);
    }

    public function test_an_unsupported_event_type_is_acknowledged()
    {
        $this->sendWebhook(['id' => 'evt_other', 'type' => 'account.updated', 'data' => []])
            ->assertOk()
            ->assertJson(['outcome' => 'unsupported_type']);
    }

    public function test_a_late_success_after_we_gave_up_records_provider_cost_without_charging_the_user()
    {
        // Every submit attempt looked like a network failure, so we told the
        // user it failed. Pixelforge had in fact accepted it.
        $generation = Generation::factory()->create();
        app(FailUnsubmittedGeneration::class)->handle($generation->id, 'submission_failed', 'Could not reach Pixelforge.');

        $snapshot = Pixelforge::generation('gen_LATE', 'succeeded', [
            'metadata' => ['pixelflow_generation_id' => (string) $generation->id],
        ]);

        $this->sendWebhook(Pixelforge::event('evt_late', 'generation.succeeded', $snapshot))
            ->assertOk()
            ->assertJson(['outcome' => 'absorbed_late_success']);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('gen_LATE', $generation->pixelforge_id);

        // Our books include what Pixelforge billed; the user pays nothing.
        $cost = GenerationCost::sole();
        $this->assertSame(Pixelforge::PROVIDER_COST_NANO, $cost->provider_cost_nano);
        $this->assertSame(0, $cost->user_cost_nano);
        $this->assertFalse($cost->user_charged);

        // A redelivery changes nothing.
        $this->sendWebhook(Pixelforge::event('evt_late_again', 'generation.succeeded', $snapshot))
            ->assertJson(['outcome' => 'ignored_stale']);
        $this->assertDatabaseCount('generation_costs', 1);
    }

    public function test_event_payload_is_stored_for_auditing()
    {
        $generation = $this->processingGeneration();

        $this->sendWebhook($this->succeededEvent($generation));

        $event = WebhookEvent::sole();
        $this->assertSame('generation.succeeded', $event->type);
        $this->assertSame($generation->pixelforge_id, $event->pixelforge_generation_id);
        $this->assertSame('applied', $event->outcome);
        $this->assertNotNull($event->processed_at);
        $this->assertSame('succeeded', $event->payload['data']['object']['status']);
    }
}
