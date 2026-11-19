<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Privacy;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Base of the Privacy Governance controllers (module 096): resolves the active tenant.
 */
abstract class PrivacyGovernanceController extends Controller
{
    protected function tenantId(Request $request): string
    {
        $tenantId = app(TenantContext::class)->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, __('tenant_errors.missing_or_unresolvable_context'));
        }

        return (string) $tenantId;
    }
}
