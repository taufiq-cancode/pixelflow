<?php

namespace App\Jobs;

use App\Actions\ApplyGenerationSnapshot;
use App\Actions\FailUnsubmittedGeneration;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a generation to Pixelforge (POST /v1/generations), retrying from the
 * queue when Pixelforge is busy (DECISIONS.md §15).
 *
 * Every attempt sends the same Idempotency-Key and the same parameters, so
 * however many times this runs, at most one Pixelforge generation exists.
 */
final class SubmitGenerationJob implements ShouldQueue
{
    use Queueable;

    /**
     * Attempts in total, counting each release back onto the queue after a
     * retryable error. With the backoff below that is roughly 3–4 minutes.
     */
    public int $tries = 8;

    public function __construct(public readonly int $generationId) {}

    public function handle(
        PixelforgeClient $client,
        ApplyGenerationSnapshot $applySnapshot,
        FailUnsubmittedGeneration $failGeneration,
    ): void {
        $generation = Generation::find($this->generationId);

        // Nothing to do if an earlier attempt (or a webhook) already linked
        // it to Pixelforge, or if it has already finished.
        if ($generation === null || $generation->pixelforge_id !== null || $generation->status->isTerminal()) {
            return;
        }

        $generation->increment('submit_attempts');

        try {
            $snapshot = $client->createGeneration($generation->toPixelforgePayload(), $generation->idempotency_key);
        } catch (PixelforgeException $exception) {
            if ($exception->isRetryable() && $this->attempts() < $this->tries) {
                $delay = $this->retryDelaySeconds($exception);

                Log::info('Pixelforge did not accept the generation yet; retrying.', [
                    'generation_id' => $generation->id,
                    'attempt' => $this->attempts(),
                    'status' => $exception->status,
                    'code' => $exception->errorCode,
                    'retry_in_seconds' => $delay,
                ]);

                $this->release($delay);

                return;
            }

            Log::warning('Pixelforge rejected the generation; marking it failed.', [
                'generation_id' => $generation->id,
                'attempt' => $this->attempts(),
                'status' => $exception->status,
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ]);

            $failGeneration->handle(
                $generation->id,
                $exception->errorCode ?? 'submission_failed',
                $exception->userMessage(),
            );

            return;
        }

        // A 202, whether new or an idempotent replay.
        $applySnapshot->handle($generation->id, $snapshot);
    }

    /**
     * Called by the queue worker when an unexpected exception used up every
     * attempt.
     */
    public function failed(?Throwable $exception): void
    {
        app(FailUnsubmittedGeneration::class)->handle(
            $this->generationId,
            'submission_failed',
            'Something went wrong while sending this generation to Pixelforge. You were not charged. Please try again.',
        );
    }

    /**
     * Exponential backoff (2, 4, 8, 16, 32, 60, 60 … seconds), never sooner
     * than Pixelforge's Retry-After, plus a little jitter so queued
     * generations don't all retry in the same second.
     */
    private function retryDelaySeconds(PixelforgeException $exception): int
    {
        $backoff = min(60, 2 ** $this->attempts());

        return max($exception->retryAfterSeconds ?? 0, $backoff) + random_int(0, 2);
    }
}
