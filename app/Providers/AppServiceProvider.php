<?php

namespace App\Providers;

use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PricingCatalogRepository;
use App\Services\Pixelforge\WebhookSignature;
use App\Services\Pricing\PricingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn () => new PixelforgeClient(
            baseUrl: config()->string('pixelforge.base_url'),
            apiKey: (string) config('pixelforge.api_key'),
            connectTimeoutSeconds: config()->integer('pixelforge.connect_timeout_seconds'),
            timeoutSeconds: config()->integer('pixelforge.timeout_seconds'),
        ));

        $this->app->singleton(PricingCatalogRepository::class, fn ($app) => new PricingCatalogRepository(
            client: $app->make(PixelforgeClient::class),
            cacheSeconds: config()->integer('pixelforge.pricing_cache_seconds'),
        ));

        $this->app->singleton(WebhookSignature::class, fn () => new WebhookSignature(
            secret: (string) config('pixelforge.webhook_secret'),
            toleranceSeconds: config()->integer('pixelforge.signature_tolerance_seconds'),
        ));

        $this->app->singleton(PricingService::class, fn () => new PricingService(
            markupBps: config()->integer('pixelflow.markup_bps'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
