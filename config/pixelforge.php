<?php

/*
|--------------------------------------------------------------------------
| Pixelforge API
|--------------------------------------------------------------------------
|
| Connection settings for the Pixelforge image API
| (https://pixelforge.promptedit.dev/docs). Each environment uses its own
| API key, because the webhook URL, signing secret and /v1/usage report
| all belong to a single key.
|
| Rates and token counts are NOT configured here: they are read from
| GET /v1/pricing (see PricingCatalogRepository) and from each
| generation's own `rates` object.
|
*/

return [

    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    'api_key' => env('PIXELFORGE_API_KEY'),

    // The whsec_… signing secret of the same API key.
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    // Webhooks whose signed timestamp is further than this from "now" are
    // rejected, to stop replayed requests. Every delivery attempt is signed
    // with a fresh timestamp, so honest retries are never affected.
    'signature_tolerance_seconds' => (int) env('PIXELFORGE_SIGNATURE_TOLERANCE', 300),

    'connect_timeout_seconds' => 5,

    'timeout_seconds' => 15,

    // How long the public price list is cached for live estimates.
    'pricing_cache_seconds' => 600,

    // Request limits published in the docs ("Create a generation"). They
    // are not exposed by any endpoint, so they live in config.
    'max_prompt_length' => 4000,

    'max_reference_images' => 4,

];
