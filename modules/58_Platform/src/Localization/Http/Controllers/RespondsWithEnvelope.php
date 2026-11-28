<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Controllers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * The {success, message, data, meta} envelope of the API and the RFC 7807 Problem Details of its errors.
 */
trait RespondsWithEnvelope
{
    /**
     * @param  array<string, mixed>  $meta
     */
    protected function envelope(mixed $data, string $message, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data, 'meta' => (object) $meta], $status);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function problem(int $status, string $title, string $detail, array $extra = []): JsonResponse
    {
        return response()->json(
            ['type' => 'https://httpstatuses.com/'.$status, 'title' => $title, 'status' => $status, 'detail' => $detail, 'success' => false, 'message' => $detail] + $extra,
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }

    protected function tenantId(): string
    {
        return (string) app(TenantContext::class)->getTenantId();
    }
}
