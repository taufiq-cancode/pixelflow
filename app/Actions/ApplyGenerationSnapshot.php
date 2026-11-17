<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Enums\SnapshotResult;
use App\Models\Generation;
use App\Models\GenerationCost;
use App\Services\Pricing\PricingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * The one place a generation's state changes from Pixelforge's side. The
 * submit response, webhooks and the reconciliation poller all come through
 * here, so ordering rules exist exactly once (DECISIONS.md §12).
 *
 * Rules:
 * - Runs in a transaction holding a row lock on the generation, so
 *   concurrent updates for the same generation are applied one at a time.
 * - The status only moves forward. A duplicate or out-of-order snapshot is
 *   ignored, and succeeded/failed are never overwritten.
 * - Moving to succeeded records the one and only cost row (also protected
 *   by the unique generation_id). Moving to failed records no cost: $0.
 */
final class ApplyGenerationSnapshot
{
    public function __construct(private readonly PricingService $pricing) {}

    /**
     * @param  array<mixed>  $snapshot  a Pixelforge generation object
     */
    public function handle(int $generationId, array $snapshot): SnapshotResult
    {
        $pixelforgeId = $snapshot['id'] ?? null;
        $incoming = is_string($snapshot['status'] ?? null) ? GenerationStatus::tryFrom($snapshot['status']) : null;

        if (! is_string($pixelforgeId) || $incoming === null) {
            throw new InvalidArgumentException('The Pixelforge generation snapshot has no valid id or status.');
        }

        return DB::transaction(function () use ($generationId, $snapshot, $pixelforgeId, $incoming): SnapshotResult {
            $generation = Generation::query()->lockForUpdate()->findOrFail($generationId);

            // Link the Pixelforge id the first time we hear about it. It may
            // come from a webhook that arrived before the POST response.
            if ($generation->pixelforge_id === null) {
                $generation->pixelforge_id = $pixelforgeId;
            } elseif ($generation->pixelforge_id !== $pixelforgeId) {
                throw new UnexpectedValueException(
                    "Generation {$generation->id} is linked to {$generation->pixelforge_id}, not {$pixelforgeId}."
                );
            }

            if ($generation->provider_rates === null && is_array($snapshot['rates'] ?? null)) {
                $generation->provider_rates = $snapshot['rates'];
            }

            $generation->last_synced_at = Date::now();

            if (! $generation->status->canTransitionTo($incoming)) {
                return $this->handleNonForwardSnapshot($generation, $incoming, $snapshot);
            }

            $generation->status = $incoming;
            $generation->started_at ??= self::time($snapshot, 'started_at');

            if ($incoming === GenerationStatus::Succeeded) {
                $generation->completed_at = self::time($snapshot, 'completed_at');
                $generation->image_url = is_string($snapshot['image_url'] ?? null) ? $snapshot['image_url'] : null;
                $generation->image_url_expires_at = self::time($snapshot, 'image_url_expires_at');
                $generation->save();

                $this->recordCost($generation, $snapshot, userCharged: true);
            } elseif ($incoming === GenerationStatus::Failed) {
                $error = is_array($snapshot['error'] ?? null) ? $snapshot['error'] : [];

                $generation->completed_at = self::time($snapshot, 'completed_at');
                $generation->error_code = is_string($error['code'] ?? null) ? $error['code'] : 'generation_failed';
                $generation->error_message = is_string($error['message'] ?? null)
                    ? $error['message']
                    : 'Pixelforge could not produce an image for this prompt. You were not charged.';
                $generation->save();
            } else {
                $generation->save();
            }

            return SnapshotResult::Applied;
        });
    }

    /**
     * @param  array<mixed>  $snapshot
     */
    private function handleNonForwardSnapshot(Generation $generation, GenerationStatus $incoming, array $snapshot): SnapshotResult
    {
        // Pixelforge never sends "succeeded" for a generation it failed
        // (both are final). So succeeded on a failed row means we gave up
        // locally after ambiguous submit errors while Pixelforge had in fact
        // accepted it. Pixelforge billed us, so record the provider cost to
        // keep our books equal to /v1/usage, but keep the generation failed
        // and don't charge the user, who was told it failed (DECISIONS.md §16).
        $lateSuccessAfterLocalFailure = $generation->status === GenerationStatus::Failed
            && $incoming === GenerationStatus::Succeeded
            && ! GenerationCost::query()->where('generation_id', $generation->id)->exists();

        $generation->save();

        if (! $lateSuccessAfterLocalFailure) {
            return SnapshotResult::Stale;
        }

        $this->recordCost($generation, $snapshot, userCharged: false);

        Log::warning('Pixelforge billed a generation we had already failed locally; cost absorbed.', [
            'generation_id' => $generation->id,
            'pixelforge_id' => $generation->pixelforge_id,
        ]);

        return SnapshotResult::AbsorbedLateSuccess;
    }

    /**
     * @param  array<mixed>  $snapshot
     */
    private function recordCost(Generation $generation, array $snapshot, bool $userCharged): void
    {
        $usage = $snapshot['usage'] ?? null;
        $rates = $snapshot['rates'] ?? null;
        $completedAt = self::time($snapshot, 'completed_at');

        if (! is_array($usage) || ! is_array($rates) || $completedAt === null) {
            throw new InvalidArgumentException('A succeeded generation must include usage, rates and completed_at.');
        }

        $cost = $this->pricing->actualCost($usage, $rates);

        GenerationCost::create([
            'generation_id' => $generation->id,
            'input_text_tokens' => $cost->tokens['input_text_tokens'],
            'input_image_tokens' => $cost->tokens['input_image_tokens'],
            'output_tokens' => $cost->tokens['output_tokens'],
            'reasoning_tokens' => $cost->tokens['reasoning_tokens'],
            'provider_cost_nano' => $cost->providerCostNano,
            'user_cost_nano' => $userCharged ? $cost->userCostNano : 0,
            'markup_bps' => $this->pricing->markupBps,
            'user_charged' => $userCharged,
            // Pixelforge bills a generation on the UTC day it completed.
            'billed_on' => $completedAt->utc()->toDateString(),
        ]);
    }

    /**
     * @param  array<mixed>  $snapshot
     */
    private static function time(array $snapshot, string $field): ?CarbonImmutable
    {
        $value = $snapshot[$field] ?? null;

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}
