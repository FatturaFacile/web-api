<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Security\HttpsRequirements;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

/**
 * Makes the application HTTPS-only: the configuration is validated at boot (the application does not start with a
 * plain-HTTP APP_URL, a cookie without Secure, a non-HTTPS CORS origin...) and every URL it generates (named routes,
 * signed URLs, mail links, OIDC/SAML redirect and callback URLs, download links) uses the https scheme and the host of
 * `app.url`, also in console, queue and scheduler, where there is no request to take them from.
 */
final class HttpsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        HttpsRequirements::assert($this->app->make('config'));

        URL::forceScheme('https');
        URL::forceRootUrl((string) config('app.url'));
    }
}
