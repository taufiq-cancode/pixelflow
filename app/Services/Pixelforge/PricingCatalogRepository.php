<?php

namespace App\Services\Pixelforge;

use App\Services\Pricing\PricingCatalog;
use Illuminate\Support\Facades\Cache;

/**
 * Loads Pixelforge's current price list, cached briefly so the live
 * estimate doesn't call Pixelforge on every keystroke. Only estimates use
 * this: actual costs always use the rates on the generation itself.
 */
final class PricingCatalogRepository
{
    private const CACHE_KEY = 'pixelforge.pricing';

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly int $cacheSeconds,
    ) {}

    public function current(): PricingCatalog
    {
        $response = Cache::remember(self::CACHE_KEY, $this->cacheSeconds, function (): array {
            $response = $this->client->pricing();
            // Parse before caching, so a malformed response is never cached.
            PricingCatalog::fromApiResponse($response);

            return $response;
        });

        return PricingCatalog::fromApiResponse($response);
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
