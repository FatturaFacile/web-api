<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

/**
 * Users authenticate with the session cookie. A Sanctum personal access token is read from the Authorization header only
 * while the token mode is enabled (`auth.token_mode.enabled`): with the flag off, a Bearer token never authenticates a
 * user (the guard falls back to the session alone), and the rows already issued stay in the database, inert. Integration
 * API keys are read by their own middleware (`api.key`) and are not affected.
 */
final class CookieAuthenticationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Sanctum::getAccessTokenFromRequestUsing(static function (Request $request): ?string {
            if (! config('auth.token_mode.enabled')) {
                return null;
            }

            $token = $request->bearerToken();

            return is_string($token) && $token !== '' ? $token : null;
        });
    }
}
