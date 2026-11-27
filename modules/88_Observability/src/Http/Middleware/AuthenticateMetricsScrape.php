<?php

declare(strict_types=1);

namespace Modules\Observability\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the Prometheus scraper may read /metrics: the bearer token of OBSERVABILITY_METRICS_TOKEN (X-Metrics-Token is accepted too) or a caller in
 * OBSERVABILITY_METRICS_ALLOWED_IPS (addresses and CIDR ranges). With neither configured the endpoint answers 404, as if it did not exist.
 */
final class AuthenticateMetricsScrape
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('observability.enabled', true) || ! config('observability.metrics.enabled', true)) {
            abort(404);
        }

        $token = (string) config('observability.metrics.token', '');
        $allowed = array_values(array_filter((array) config('observability.metrics.allowed_ips', []), 'is_string'));

        if ($token === '' && $allowed === []) {
            abort(404);
        }

        if ($token !== '') {
            $given = (string) ($request->bearerToken() ?? $request->header('X-Metrics-Token', ''));

            if ($given !== '' && hash_equals($token, $given)) {
                return $next($request);
            }
        }

        if ($allowed !== [] && IpUtils::checkIp((string) $request->ip(), $allowed)) {
            return $next($request);
        }

        abort(403);
    }
}
