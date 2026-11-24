<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The tenant and the user of the request, and the file downloads of the Italian VAT controllers.
 */
trait ResolvesItalianVatContext
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
