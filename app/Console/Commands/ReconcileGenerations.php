<?php

namespace App\Console\Commands;

use App\Actions\ApplyGenerationSnapshot;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for lost webhooks (DECISIONS.md §17). Scheduled every minute.
 *
 * 1. Polls generations Pixelforge accepted that have gone quiet, and applies
 *    what Pixelforge reports through the same code path as webhooks.
 * 2. Re-queues generations whose submission job was lost. Safe because
 *    every submission reuses the generation's Idempotency-Key.
 */
final class ReconcileGenerations extends Command
{
    protected $signature = 'pixelflow:reconcile-generations
        {--limit=20 : Maximum generations to poll per run (each poll is one API request)}
        {--quiet-minutes=2 : Poll generations with no update for this many minutes}';

    protected $description = 'Poll Pixelforge for stuck generations and re-queue lost submissions';

    /**
     * An unsubmitted generation untouched for this long has no live job:
     * the submit job touches the row on every attempt and gives up well
     * within this time.
     */
    private const ORPHANED_SUBMISSION_MINUTES = 15;

    public function handle(PixelforgeClient $client, ApplyGenerationSnapshot $applySnapshot): int
    {
        $polled = $this->pollQuietGenerations($client, $applySnapshot);
        $requeued = $this->requeueOrphanedSubmissions();

        $this->info("Polled {$polled} generation(s), re-queued {$requeued} submission(s).");

        return self::SUCCESS;
    }

    private function pollQuietGenerations(PixelforgeClient $client, ApplyGenerationSnapshot $applySnapshot): int
    {
        $quietSince = Date::now()->subMinutes((int) $this->option('quiet-minutes'));

        $generations = Generation::query()
            ->whereIn('status', GenerationStatus::inFlight())
            ->whereNotNull('pixelforge_id')
            ->where(fn ($query) => $query
                ->whereNull('last_synced_at')
                ->orWhere('last_synced_at', '<', $quietSince))
            ->orderBy('last_synced_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $polled = 0;

        foreach ($generations as $generation) {
            try {
                $snapshot = $client->getGeneration((string) $generation->pixelforge_id);
                $result = $applySnapshot->handle($generation->id, $snapshot);
                $polled++;

                $this->line("{$generation->pixelforge_id}: {$snapshot['status']} ({$result->value})");
            } catch (PixelforgeException $exception) {
                Log::warning('Could not poll a Pixelforge generation.', [
                    'generation_id' => $generation->id,
                    'status' => $exception->status,
                    'code' => $exception->errorCode,
                ]);
                $this->warn("{$generation->pixelforge_id}: {$exception->getMessage()}");

                // Rate limited: stop and let the next run continue.
                if ($exception->status === 429) {
                    break;
                }
            }
        }

        return $polled;
    }

    private function requeueOrphanedSubmissions(): int
    {
        $ids = Generation::query()
            ->where('status', GenerationStatus::Queued)
            ->whereNull('pixelforge_id')
            ->where('updated_at', '<', Date::now()->subMinutes(self::ORPHANED_SUBMISSION_MINUTES))
            ->pluck('id');

        foreach ($ids as $id) {
            SubmitGenerationJob::dispatch((int) $id);
        }

        return $ids->count();
    }
}
