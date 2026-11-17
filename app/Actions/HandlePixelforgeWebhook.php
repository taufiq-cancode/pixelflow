<?php

namespace App\Actions;

use App\Models\Generation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Processes a verified Pixelforge webhook event exactly once.
 *
 * Everything happens in one transaction. The event row is claimed first
 * with INSERT … IGNORE on the unique event_id: if another delivery of the
 * same event already claimed it, nothing else runs. If processing throws,
 * the claim is rolled back too, so Pixelforge's retry processes it again.
 */
final class HandlePixelforgeWebhook
{
    public const DUPLICATE = 'duplicate';

    public const PING = 'ping';

    public const UNMATCHED = 'unmatched';

    public const UNSUPPORTED_TYPE = 'unsupported_type';

    public function __construct(private readonly ApplyGenerationSnapshot $applySnapshot) {}

    /**
     * @param  array<mixed>  $event  a well-formed event (checked by the controller)
     * @return string the outcome, stored on the event row
     */
    public function handle(array $event, ?int $deliveryAttempt = null): string
    {
        $eventId = (string) $event['id'];
        $type = (string) $event['type'];
        $snapshot = is_array($event['data'] ?? null) && is_array($event['data']['object'] ?? null)
            ? $event['data']['object']
            : null;
        $pixelforgeId = is_string($snapshot['id'] ?? null) ? $snapshot['id'] : null;

        return DB::transaction(function () use ($event, $eventId, $type, $snapshot, $pixelforgeId, $deliveryAttempt): string {
            $now = Date::now();

            $claimed = DB::table('webhook_events')->insertOrIgnore([
                'event_id' => $eventId,
                'type' => $type,
                'pixelforge_generation_id' => $pixelforgeId,
                'delivery_attempt' => $deliveryAttempt,
                'payload' => json_encode($event, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($claimed === 0) {
                // Already processed: a duplicate delivery changes nothing.
                return self::DUPLICATE;
            }

            $outcome = $this->process($eventId, $type, $snapshot);

            DB::table('webhook_events')->where('event_id', $eventId)->update([
                'outcome' => $outcome,
                'processed_at' => $now,
                'updated_at' => $now,
            ]);

            return $outcome;
        });
    }

    /**
     * @param  array<mixed>|null  $snapshot
     */
    private function process(string $eventId, string $type, ?array $snapshot): string
    {
        if ($type === 'ping') {
            return self::PING;
        }

        if (! str_starts_with($type, 'generation.') || $snapshot === null) {
            return self::UNSUPPORTED_TYPE;
        }

        $generationId = $this->findLocalGenerationId($snapshot);

        if ($generationId === null) {
            // Not one of ours. Retrying won't change that, so acknowledge it.
            Log::warning('Pixelforge webhook for an unknown generation.', [
                'event_id' => $eventId,
                'pixelforge_id' => $snapshot['id'] ?? null,
            ]);

            return self::UNMATCHED;
        }

        return $this->applySnapshot->handle($generationId, $snapshot)->value;
    }

    /**
     * Finds our generation by its Pixelforge id or, when the webhook beat the
     * POST response and the id isn't linked yet, by the local id we sent in
     * the metadata.
     *
     * @param  array<mixed>  $snapshot
     */
    private function findLocalGenerationId(array $snapshot): ?int
    {
        $id = Generation::query()->where('pixelforge_id', $snapshot['id'] ?? null)->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        $metadata = is_array($snapshot['metadata'] ?? null) ? $snapshot['metadata'] : [];
        $localId = $metadata['pixelflow_generation_id'] ?? null;

        if (! is_string($localId) || ! ctype_digit($localId)) {
            return null;
        }

        $id = Generation::query()->whereKey((int) $localId)->whereNull('pixelforge_id')->value('id');

        return $id === null ? null : (int) $id;
    }
}
