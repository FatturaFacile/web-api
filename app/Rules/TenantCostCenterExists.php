<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Validates that a UUID references a non-deleted cost center in the active tenant
 * across core cost centers (Mod 02), analytical cost centers, or HR cost centers (Mod 019).
 */
final readonly class TenantCostCenterExists implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $tenantId = app(TenantContext::class)->getTenantId();
        if ($tenantId === null || $tenantId === '') {
            $fail(__('purchase_requisition.validation.cost_center_not_found'));

            return;
        }

        $costCenterId = trim($value);

        foreach (['cost_centers', 'analytical_cost_centers', 'hr_cost_centers'] as $table) {
            $exists = DB::table($table)
                ->where('tenant_id', $tenantId)
                ->where('id', $costCenterId)
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                return;
            }
        }

        $fail(__('purchase_requisition.validation.cost_center_not_found'));
    }
}
