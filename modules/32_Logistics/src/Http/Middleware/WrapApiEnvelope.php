<?php

declare(strict_types=1);

namespace Modules\Logistics\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives the logistics endpoints the platform-wide envelope `{ success, message, data, meta? }` that the frontend list
 * facades expect (Laravel's bare paginator output is folded into `data` + `meta`).
 */
final class WrapApiEnvelope
{
    private const META_KEYS = ['current_page', 'from', 'last_page', 'per_page', 'to', 'total'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $response instanceof JsonResponse || $response->getStatusCode() === 204) {
            return $response;
        }

        $body = $response->getData(true);
        if (! is_array($body) || array_key_exists('success', $body)) {
            return $response;
        }

        $ok = $response->isSuccessful();
        if (! $ok) {
            $body = ['success' => false, 'message' => (string) ($body['message'] ?? 'Error')] + $body;
        } elseif (array_key_exists('current_page', $body) && array_key_exists('data', $body)) {
            $body = [
                'success' => true,
                'message' => '',
                'data' => $body['data'],
                'meta' => array_intersect_key($body, array_flip(self::META_KEYS)) + ['from' => null, 'to' => null],
            ];
        } else {
            $body = ['success' => true, 'message' => ''] + $body;
        }

        $response->setData($body);

        return $response;
    }
}
