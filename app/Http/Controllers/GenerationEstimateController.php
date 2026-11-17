<?php

namespace App\Http\Controllers;

use App\Http\Requests\EstimateGenerationRequest;
use App\Services\Pixelforge\PricingCatalogRepository;
use App\Services\Pricing\PricingService;
use Illuminate\Http\JsonResponse;

/**
 * POST /generations/estimate: the live price range shown on the Generate
 * page. Computed by the same PricingService that prices the submission, so
 * the UI never re-implements money maths.
 */
final class GenerationEstimateController
{
    public function __invoke(
        EstimateGenerationRequest $request,
        PricingCatalogRepository $catalogs,
        PricingService $pricing,
    ): JsonResponse {
        $estimate = $pricing->estimate(
            $catalogs->current(),
            $request->string('prompt')->toString(),
            $request->string('size')->toString(),
            $request->string('quality')->toString(),
            $request->integer('reference_image_count'),
        );

        return response()->json($estimate->toUserArray());
    }
}
