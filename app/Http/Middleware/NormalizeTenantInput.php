<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeTenantInput
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->normalizeTenantInput($request);
        $this->normalizeTenantHeaders($request);

        return $next($request);
    }

    /**
     * Normalize tenant_id in query and body parameters.
     */
    private function normalizeTenantInput(Request $request): void
    {
        if ($request->has('tenant_id')) {
            $value = $request->input('tenant_id');

            if ($this->shouldNormalize($value)) {
                $request->merge(['tenant_id' => null]);

                if ($request->query->has('tenant_id')) {
                    $request->query->set('tenant_id', null);
                }

                if ($request->request->has('tenant_id')) {
                    $request->request->set('tenant_id', null);
                }

                if ($request->isJson() && $request->json() !== null && $request->json()->has('tenant_id')) {
                    $request->json()->set('tenant_id', null);
                }
            }
        } elseif ($request->query->has('tenant_id')) {
            $value = $request->query->get('tenant_id');

            if ($this->shouldNormalize($value)) {
                $request->query->set('tenant_id', null);
                $request->merge(['tenant_id' => null]);
            }
        }
    }

    /**
     * Normalize tenant headers when passed as 'default' or nullish.
     */
    private function normalizeTenantHeaders(Request $request): void
    {
        foreach (['X-Tenant-Id', 'x-tenant-id', 'X-Tenant-ID'] as $header) {
            if ($request->headers->has($header)) {
                $value = $request->header($header);

                if ($this->shouldNormalize($value)) {
                    $request->headers->remove($header);
                }
            }
        }
    }

    /**
     * Determine if a tenant value should be normalized to null.
     */
    private function shouldNormalize(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return in_array($normalized, ['default', 'null', 'undefined', ''], true);
        }

        return false;
    }
}
