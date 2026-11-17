<?php

namespace App\Http\Requests\Concerns;

use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\PricingCatalogRepository;
use App\Services\Pricing\PricingCatalog;
use Illuminate\Validation\ValidationException;

/**
 * Valid sizes and qualities come from Pixelforge's live price list, so a
 * size Pixelforge adds or removes is accepted or rejected automatically.
 */
trait UsesPricingCatalog
{
    protected function pricingCatalog(): PricingCatalog
    {
        try {
            return app(PricingCatalogRepository::class)->current();
        } catch (PixelforgeException) {
            throw ValidationException::withMessages([
                'pricing' => 'Live pricing is temporarily unavailable, so this generation can\'t be priced. Please try again in a moment.',
            ]);
        }
    }
}
