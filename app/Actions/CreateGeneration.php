<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PricingCatalogRepository;
use App\Services\Pricing\PricingService;
use Illuminate\Support\Str;

/**
 * Records a new generation with its upfront estimate and queues it for
 * submission to Pixelforge. The row exists before Pixelforge hears about
 * it, so a webhook that beats the POST response can always find it.
 */
final class CreateGeneration
{
    public function __construct(
        private readonly PricingCatalogRepository $catalogs,
        private readonly PricingService $pricing,
    ) {}

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images: list<string>}  $input
     */
    public function handle(User $user, array $input): Generation
    {
        $estimate = $this->pricing->estimate(
            $this->catalogs->current(),
            $input['prompt'],
            $input['size'],
            $input['quality'],
            count($input['reference_images']),
        );

        $generation = $user->generations()->create([
            'idempotency_key' => (string) Str::uuid(),
            'status' => GenerationStatus::Queued,
            'prompt' => $input['prompt'],
            'size' => $input['size'],
            'quality' => $input['quality'],
            'reference_images' => $input['reference_images'],
            'estimated_min_cost_nano' => $estimate->userMinNano,
            'estimated_max_cost_nano' => $estimate->userMaxNano,
        ]);

        SubmitGenerationJob::dispatch($generation->id);

        return $generation;
    }
}
