<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Marks a generation failed because Pixelforge never accepted it (the
 * submission was rejected, or we gave up retrying). No cost is recorded,
 * so the user pays $0.
 *
 * Does nothing once Pixelforge has told us about the generation (it is
 * linked to a pixelforge_id), because then Pixelforge owns the outcome.
 */
final class FailUnsubmittedGeneration
{
    public function handle(int $generationId, string $errorCode, string $message): bool
    {
        return DB::transaction(function () use ($generationId, $errorCode, $message): bool {
            $generation = Generation::query()->lockForUpdate()->find($generationId);

            if ($generation === null
                || $generation->pixelforge_id !== null
                || ! $generation->status->canTransitionTo(GenerationStatus::Failed)) {
                return false;
            }

            $generation->status = GenerationStatus::Failed;
            $generation->error_code = $errorCode;
            $generation->error_message = $message;
            $generation->completed_at = Date::now();
            $generation->save();

            return true;
        });
    }
}
