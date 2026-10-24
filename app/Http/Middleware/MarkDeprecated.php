<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Flags legacy endpoints so clients can migrate gradually: every response carries `X-Deprecated: true` and,
 * when a replacement is given, a `Link` header pointing to the successor version.
 *
 * Usage: `->middleware('deprecated:/api/v1/new/endpoint')`
 */
class MarkDeprecated
{
    public function handle(Request $request, Closure $next, ?string $successor = null): Response
    {
        $response = $next($request);

        $response->headers->set('X-Deprecated', 'true');

        if ($successor !== null) {
            $response->headers->set('Link', "<{$successor}>; rel=\"successor-version\"");
        }

        return $response;
    }
}
