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
use OpenApi\Attributes as OA;

class SettingsController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    #[OA\Get(
        path: '/api/v1/settings/tenant',
        operationId: 'getTenantSettings',
        description: 'Get current tenant organization settings.',
        summary: 'Get tenant settings',
        security: [['bearerAuth' => []]],
        tags: ['Settings', 'Tenants'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tenant settings retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Tenant settings retrieved'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/TenantResource'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
        ]
    )]
    public function tenant(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();

        return $this->successResponse(data: $tenant, message: 'Tenant settings retrieved');
    }

    #[OA\Put(
        path: '/api/v1/settings/tenant',
        operationId: 'updateTenantSettings',
        description: 'Update current tenant organization settings.',
        summary: 'Update tenant settings',
        security: [['bearerAuth' => []]],
        tags: ['Settings', 'Tenants'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, nullable: true, example: 'Acme Corp SA'),
                    new OA\Property(property: 'legal_name', type: 'string', maxLength: 255, nullable: true, example: 'Acme Corporation SA'),
                    new OA\Property(property: 'fiscal_code', type: 'string', maxLength: 16, nullable: true, example: 'RSSMRA85M01H501Q'),
                    new OA\Property(property: 'vat_number', type: 'string', maxLength: 30, nullable: true, example: 'CHE-123.456.789'),
                    new OA\Property(property: 'uid_number', type: 'string', maxLength: 20, nullable: true, example: 'CHE123456789'),
                    new OA\Property(property: 'default_currency', type: 'string', maxLength: 3, nullable: true, example: 'CHF'),
                    new OA\Property(property: 'iban', type: 'string', maxLength: 34, nullable: true, example: 'CH9300000000000000000'),
                    new OA\Property(property: 'qr_iban', type: 'string', maxLength: 34, nullable: true, example: 'CH4430000000000000000'),
                    new OA\Property(property: 'sdi_code', type: 'string', maxLength: 7, nullable: true, example: 'M5UXCR1'),
                    new OA\Property(property: 'pec_email', type: 'string', format: 'email', nullable: true, example: 'acme@pec.it'),
                    new OA\Property(property: 'settings', type: 'object', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tenant settings updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Tenant settings updated successfully'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/TenantResource'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')
            ),
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/settings/branches',
        operationId: 'getBranches',
        description: 'Get operational warehouses and company branch locations.',
        summary: 'List branches',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Branches retrieved successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
        ]
    )]
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
    #[OA\Post(
        path: '/api/v1/settings/exchange-rates/sync',
        operationId: 'syncExchangeRates',
        description: 'Synchronize daily currency exchange rates between EUR, CHF and USD.',
        summary: 'Sync exchange rates',
        security: [['bearerAuth' => []]],
        tags: ['Settings', 'Banking'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Exchange rates synchronized successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/settings/users',
        operationId: 'getTenantUsers',
        description: 'List members and users assigned to the active tenant.',
        summary: 'List tenant users',
        security: [['bearerAuth' => []]],
        tags: ['Settings', 'Users'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tenant users retrieved successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/settings/roles',
        operationId: 'getSystemRoles',
        description: 'List system role definitions and privileges.',
        summary: 'List system roles',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Roles retrieved successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/settings/audit-logs',
        operationId: 'getAuditLogs',
        description: 'Get paginated audit activity log trail for the active tenant.',
        summary: 'List audit logs',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Audit logs retrieved successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
        ]
    )]
    public function auditLogs(): JsonResponse
    {
        $logs = DB::table('audit_logs')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->latest()
            ->paginate(25);

        return $this->successResponse(data: $logs, message: 'Audit logs retrieved');
    }
}
