<?php

namespace App\Providers;

use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\WebhookSignature;
use App\Pricing\Markup;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn () => new PixelforgeClient(
            (string) config('pixelforge.base_url'),
            config('pixelforge.api_key'),
            (int) config('pixelforge.timeout'),
        ));

        $this->app->bind(WebhookSignature::class, fn () => new WebhookSignature(
            (string) config('pixelforge.webhook_secret'),
            (int) config('pixelforge.webhook_tolerance'),
        ));

        $this->app->bind(Markup::class, fn () => Markup::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Gate::define('admin', fn (User $user) => $user->is_admin);
    }
}
