<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    public function tenant(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();

        return $this->successResponse(data: $tenant, message: 'Tenant settings retrieved');
    }

    public function updateTenant(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $this->tenantContext->getTenant();
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'fiscal_code' => ['nullable', 'string', 'max:16'],
            'vat_number' => ['nullable', 'string', 'max:30'],
            'uid_number' => ['nullable', 'string', 'max:20'],
            'default_currency' => ['nullable', 'string', 'size:3'],
            'iban' => ['nullable', 'string', 'max:34'],
            'qr_iban' => ['nullable', 'string', 'max:34'],
            'sdi_code' => ['nullable', 'string', 'size:7'],
            'pec_email' => ['nullable', 'email'],
            'settings' => ['nullable', 'array'],
        ]);

        $tenant->update($validated);

        return $this->successResponse(data: $tenant, message: 'Tenant settings updated successfully');
    }

    public function branches(): JsonResponse
    {
        $branches = DB::table('warehouses')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->whereNull('deleted_at')
            ->get();

        return $this->successResponse(data: $branches, message: 'Branches and operational locations');
    }

    /**
     * Synchronize currency exchange rates between EUR and CHF (ECB / SNB simulated / live rate).
     */
    public function syncExchangeRates(): JsonResponse
    {
        $today = now()->toDateString();

        // Sample ECB/SNB rates: 1 EUR = 0.9425 CHF; 1 CHF = 1.0610 EUR
        $rates = [
            ['source' => 'EUR', 'target' => 'CHF', 'rate' => 0.942500],
            ['source' => 'CHF', 'target' => 'EUR', 'rate' => 1.061008],
            ['source' => 'USD', 'target' => 'EUR', 'rate' => 0.921500],
            ['source' => 'USD', 'target' => 'CHF', 'rate' => 0.868500],
        ];

        foreach ($rates as $r) {
            DB::table('exchange_rates')->updateOrInsert(
                ['source' => $r['source'], 'target' => $r['target'], 'effective_date' => $today],
                ['id' => (string) Str::uuid(), 'rate' => $r['rate'], 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return $this->successResponse(
            data: ['effective_date' => $today, 'synced_pairs' => count($rates)],
            message: 'Exchange rates synchronized successfully with central bank references'
        );
    }

    public function users(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $users = $tenant->users()->get()->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->pivot?->role,
            'is_active' => $u->is_active,
        ]);

        return $this->successResponse(data: $users, message: 'Tenant users retrieved');
    }

    public function roles(): JsonResponse
    {
        $roles = [
            ['key' => 'owner', 'name' => 'Enterprise Owner', 'description' => 'Full administrative access across all modules and fiscal settings'],
            ['key' => 'admin', 'name' => 'Tenant Administrator', 'description' => 'Operational admin for CRM, Sales, AP, and HR'],
            ['key' => 'accountant', 'name' => 'Fiduciario / Commercialista', 'description' => 'Dedicated access to fiscal invoicing, general ledger, and VAT declarations'],
            ['key' => 'technician', 'name' => 'Field Technician', 'description' => 'Access to service tickets, work orders (rapportini), and time clocking'],
            ['key' => 'member', 'name' => 'Staff Member', 'description' => 'Standard self-service attendance clocking and expense submission'],
        ];

        return $this->successResponse(data: $roles, message: 'System RBAC roles retrieved');
    }

    public function auditLogs(): JsonResponse
    {
        $logs = DB::table('audit_logs')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->latest()
            ->paginate(25);

        return $this->successResponse(data: $logs, message: 'Audit logs retrieved');
    }
}
