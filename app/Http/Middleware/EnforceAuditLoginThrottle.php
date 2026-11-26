<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Enterprise\AuditLedger\Support\LoginThrottle;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses the login of an IP address that failed too many times in a row (Mod 004 / Mod 005): the block is set by the audit
 * ledger when it records the failure that crosses the limit, and it expires on its own.
 */
final class EnforceAuditLoginThrottle
{
    public function __construct(private readonly LoginThrottle $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->throttle->isBlocked($request->ip())) {
            $retry = $this->throttle->retryAfterSeconds();

            return response()->json([
                'type' => 'https://httpstatuses.com/429',
                'title' => 'Too Many Requests',
                'status' => 429,
                'detail' => 'Too many failed sign-in attempts from this address. Try again later.',
                'instance' => $request->getRequestUri(),
            ], 429, ['Content-Type' => 'application/problem+json', 'Retry-After' => (string) $retry]);
        }

        return $next($request);
    }
}
