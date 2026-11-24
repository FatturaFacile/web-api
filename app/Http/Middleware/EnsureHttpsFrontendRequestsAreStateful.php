<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * Sanctum's stateful check, for HTTPS frontends only: Sanctum strips the scheme before comparing the Origin/Referer
 * with the stateful domains, so a page served over plain HTTP on a listed host would get the cookie session too. Here
 * the Origin/Referer must be an https URL as well.
 */
final class EnsureHttpsFrontendRequestsAreStateful extends EnsureFrontendRequestsAreStateful
{
    /**
     * @param  Request  $request
     */
    public static function fromFrontend($request): bool
    {
        $source = $request->headers->get('referer') ?: $request->headers->get('origin');

        if (! is_string($source) || ! Str::startsWith(strtolower($source), 'https://')) {
            return false;
        }

        return parent::fromFrontend($request);
    }
}
