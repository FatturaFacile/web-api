<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\ExchangeRateProviderInterface;
use App\Enums\SupportedCountry;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Rules\ValidFiscalIdentifier;
use App\Services\Auth\TaxIdentifier;
use App\Services\SwissQrBill\QrBillGeneratorService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantMembershipService;
use Closure;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Models\EnasarcoParameter;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\SDI\Enums\RegimeFiscale;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class SettingsController extends Controller
{
    use ApiResponse;

    /**
     * Subtrees of tenants.settings written only by their own endpoints (preferences, two-factor policy).
     *
     * @var list<string>
     */
    private const array ReservedSettingsKeys = ['preferences', 'security'];

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
                    new OA\Property(property: 'eori_number', type: 'string', maxLength: 17, nullable: true, example: 'IT12345678901'),
                    new OA\Property(property: 'country', description: 'Company country (SupportedCountry). Cannot change once invoices or journal entries exist.', type: 'string', enum: ['IT', 'CH', 'FR', 'ES', 'DE', 'US'], example: 'FR'),
                    new OA\Property(property: 'default_currency', type: 'string', maxLength: 3, nullable: true, example: 'CHF'),
                    new OA\Property(property: 'iban', type: 'string', maxLength: 34, nullable: true, example: 'CH9300000000000000000'),
                    new OA\Property(property: 'qr_iban', type: 'string', maxLength: 34, nullable: true, example: 'CH4430000000000000000'),
                    new OA\Property(property: 'sdi_code', type: 'string', maxLength: 7, nullable: true, example: 'M5UXCR1'),
                    new OA\Property(property: 'pec_email', type: 'string', format: 'email', nullable: true, example: 'acme@pec.it'),
                    new OA\Property(property: 'customs_approved_exporter_number', type: 'string', maxLength: 50, nullable: true, example: 'CH-AE-123456'),
                    new OA\Property(property: 'rex_exporter_number', type: 'string', maxLength: 50, nullable: true, example: 'CHREX123456789'),
                    new OA\Property(property: 'settings', description: 'Free settings. `regime_fiscale` must be a RegimeFiscale of the FatturaPA schema (RF01-RF20).', type: 'object', nullable: true),
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
            'vat_number' => [
                'nullable', 'string', 'max:30',
                function (string $attribute, mixed $value, Closure $fail) use ($tenant): void {
                    if (is_string($value) && TaxIdentifier::vatNumberTaken($value, (string) $tenant->id)) {
                        $fail(__('validation.unique', ['attribute' => $attribute]));
                    }
                },
            ],
            'uid_number' => ['nullable', 'string', 'max:20'],
            'country' => ['sometimes', 'required', 'string', Rule::enum(SupportedCountry::class)],
            'default_currency' => ['nullable', 'string', 'size:3'],
            'iban' => ['nullable', 'string', 'max:34'],
            'qr_iban' => [
                'nullable', 'string', 'max:34',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && $value !== '' && ! app(QrBillGeneratorService::class)->isValidQrIban($value)) {
                        $fail(__('qr_bill.errors.invalid_qr_iban'));
                    }
                },
            ],
            'qrr_prefix' => ['nullable', 'string', 'regex:/^\d{1,6}$/'],
            'default_reference_type' => ['nullable', Rule::in(['QRR', 'SCOR', 'NON'])],
            'qr_bill_perforation' => ['nullable', Rule::in(['NONE', 'DASHED_LINE', 'DASHED_LINE_WITH_SCISSORS'])],
            'sdi_code' => ['nullable', 'string', 'size:7'],
            'pec_email' => ['nullable', 'email'],
            'customs_approved_exporter_number' => ['nullable', 'string', 'max:50'],
            'rex_exporter_number' => ['nullable', 'string', 'max:50'],
            'eori_number' => ['nullable', 'string', 'max:17', new ValidFiscalIdentifier('eori')],
            'settings' => ['nullable', 'array'],
            'settings.regime_fiscale' => ['nullable', Rule::enum(RegimeFiscale::class)],
            'settings.enasarco_agent_type' => ['nullable', Rule::in(EnasarcoParameter::AGENT_TYPES)],
        ]);

        if (isset($validated['country']) && $validated['country'] !== $tenant->country && $this->hasPostedAccountingData()) {
            throw ValidationException::withMessages(['country' => [__('validation.tenant_country_locked')]]);
        }

        // The settings are merged, never replaced: the preferences and the security policy have their own endpoints, and
        // a body that sent them here would wipe them (two-factor requirement included).
        if (array_key_exists('settings', $validated)) {
            $incoming = Arr::except((array) ($validated['settings'] ?? []), self::ReservedSettingsKeys);
            $validated['settings'] = [...(array) $tenant->settings, ...$incoming];
        }

        $tenant->update($validated);

        return $this->successResponse(data: $tenant, message: 'Tenant settings updated successfully');
    }

    /**
     * The country drives the chart of accounts, VAT codes and payroll rules: once documents or journal entries exist it
     * can no longer change without corrupting them.
     */
    private function hasPostedAccountingData(): bool
    {
        return Invoice::query()->exists()
            || PassiveInvoice::query()->exists()
            || JournalEntry::query()->exists();
    }

    #[OA\Get(
        path: '/api/v1/settings/branches',
        operationId: 'getBranches',
        description: 'Get operational warehouses and company branch locations. Grid contract: sort_by, sort_dir, filter, filter_like, grid_values.',
        summary: 'List branches',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1000)),
        ],
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
    public function branches(Request $request): JsonResponse
    {
        $branches = Warehouse::query()
            ->orderBy('name')
            ->gridPaginate(min(1000, max(1, (int) $request->query('per_page', 1000))));

        return $this->successResponse(
            data: $branches->getCollection(),
            message: 'Branches and operational locations',
            meta: ['current_page' => $branches->currentPage(), 'last_page' => $branches->lastPage(), 'per_page' => $branches->perPage(), 'total' => $branches->total()],
        );
    }

    /**
     * Synchronize currency exchange rates between EUR and CHF (ECB / SNB simulated / live rate).
     */
    #[OA\Post(
        path: '/api/v1/settings/exchange-rates/sync',
        operationId: 'syncExchangeRates',
        description: 'Synchronize daily currency exchange rates between EUR, CHF and USD. The rates are central data shared by every tenant: platform administrators only.',
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
                response: 403,
                description: 'Not a platform administrator',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
        ]
    )]
    public function syncExchangeRates(ExchangeRateProviderInterface $rateProvider): JsonResponse
    {
        $today = now()->toDateString();
        $rates = $rateProvider->getRates();
        // A tenant stores its own copy of the rates: the global rows (null tenant) are changed by platform code only.
        $tenantId = app(TenantContext::class)->getTenantId();

        foreach ($rates as $r) {
            ExchangeRate::updateOrCreate(
                ['tenant_id' => $tenantId, 'source' => $r['source'], 'target' => $r['target'], 'effective_date' => $today],
                ['rate' => $r['rate']]
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
    public function users(TenantMembershipService $memberships): JsonResponse
    {
        return $this->successResponse(data: $memberships->members($this->tenantContext->getTenant()), message: 'Tenant users retrieved');
    }

    #[OA\Get(
        path: '/api/v1/settings/audit-logs',
        operationId: 'getAuditLogs',
        description: 'Get paginated audit activity log trail for the active tenant, guarded by admin or compliance roles.',
        summary: 'List audit logs',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Settings', 'Audit'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'module', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'action', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
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
            new OA\Response(
                response: 403,
                description: 'Forbidden: strict admin or compliance role required',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
        ]
    )]
    public function auditLogs(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        $tenant = $this->tenantContext->getTenant();

        if (! $user || ! $tenant) {
            return $this->problemDetailsResponse(
                title: 'Unauthorized',
                detail: 'Authenticated tenant context is required.',
                status: Response::HTTP_UNAUTHORIZED
            );
        }

        $role = $user->getRoleInTenant($tenant->id);
        $isAuthorized = in_array($role, ['admin', 'owner', 'compliance'], true);

        if (! $isAuthorized) {
            return $this->problemDetailsResponse(
                title: 'Forbidden',
                detail: 'Strict admin or compliance role required to access the audit trail.',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $query = AuditLedgerEntry::with('user')
            ->where('tenant_id', $tenant->id)
            ->orderBy('event_timestamp', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('module')) {
            $query->where('module_code', $request->query('module'));
        }

        if ($request->filled('action')) {
            $query->where('action', strtoupper((string) $request->query('action')));
        }

        $perPage = min(100, max(5, (int) $request->query('per_page', 25)));
        $logs = $query->gridPaginate($perPage);

        return $this->successResponse(
            data: $logs,
            message: 'Unified audit ledger retrieved successfully'
        );
    }
}
