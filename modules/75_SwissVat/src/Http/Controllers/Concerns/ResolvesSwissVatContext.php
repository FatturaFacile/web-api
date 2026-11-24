<?php

declare(strict_types=1);

namespace Modules\SwissVat\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Shared tenant/user resolution and attachment response helpers for Swiss VAT controllers.
 */
trait ResolvesSwissVatContext
{
    private function tenantId(): string
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        return (string) $tenantId;
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }

    private function download(string $content, string $fileName, string $mimeType): Response
    {
        return response($content, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $fileName).'"',
        ]);
    }
}
