<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Http\Controllers\Concerns;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Response;

/**
 * The tenant of the request and the file downloads of the crisis monitoring controllers.
 */
trait ResolvesCrisisContext
{
    private function tenant(): Tenant
    {
        $tenant = app(TenantContext::class)->getTenant();

        if ($tenant === null) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        return $tenant;
    }

    private function pdfDownload(string $content, string $fileName): Response
    {
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $fileName).'"',
        ]);
    }
}
