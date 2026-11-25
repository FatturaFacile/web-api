<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Http\Controllers\Concerns;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The tenant and the user of the request, and the file downloads of the liquidity planning controllers.
 */
trait ResolvesLiquidityContext
{
    private function tenant(): Tenant
    {
        $tenant = app(TenantContext::class)->getTenant();

        if ($tenant === null) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        return $tenant;
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
