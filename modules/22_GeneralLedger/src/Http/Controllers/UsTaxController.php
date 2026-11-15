<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Models\UsTaxJurisdiction;
use Modules\GeneralLedger\Models\UsTaxNexus;
use Modules\GeneralLedger\Models\UsTaxRate;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Services\Localization\UsSalesTaxRateCatalog;
use Modules\GeneralLedger\Services\Localization\UsSalesTaxService;
use OpenApi\Attributes as OA;

/**
 * United States sales tax: jurisdictions with dated rates, nexus states and the tax calculation.
 */
final class UsTaxController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly UsSalesTaxService $salesTax,
    ) {}

    #[OA\Get(path: '/api/v1/general-ledger/us-tax/jurisdictions', operationId: 'listUsTaxJurisdictions', description: 'Sales tax jurisdictions of the active tenant with their rates. Filter: state.', summary: 'List US tax jurisdictions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Tax Settlements'], parameters: [
        new OA\Parameter(name: 'state', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 2)),
    ], responses: [
        new OA\Response(response: 200, description: 'Jurisdictions', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function jurisdictions(Request $request): JsonResponse
    {
        $this->authorize('viewRegisters', VatSettlement::class);

        return response()->json(['data' => UsTaxJurisdiction::query()
            ->where('tenant_id', $this->tenantId())
            ->when($request->filled('state'), static fn ($query) => $query->where('state', strtoupper((string) $request->query('state'))))
            ->with('rates')
            ->orderBy('state')
            ->orderBy('code')
            ->get()]);
    }

    #[OA\Post(path: '/api/v1/general-ledger/us-tax/jurisdictions', operationId: 'storeUsTaxJurisdiction', description: 'Adds a sales tax jurisdiction. The code is unique in the tenant.', summary: 'Add a US tax jurisdiction', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['code', 'name', 'state', 'level'], properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 40),
        new OA\Property(property: 'name', type: 'string', maxLength: 160),
        new OA\Property(property: 'state', type: 'string', example: 'CA'),
        new OA\Property(property: 'level', type: 'string', enum: ['state', 'county', 'city', 'special']),
        new OA\Property(property: 'county', type: 'string', nullable: true),
        new OA\Property(property: 'city', type: 'string', nullable: true),
        new OA\Property(property: 'zip_prefix', type: 'string', nullable: true),
    ])), tags: ['General Ledger Tax Settlements'], responses: [
        new OA\Response(response: 201, description: 'Jurisdiction added', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function storeJurisdiction(Request $request): JsonResponse
    {
        $this->authorize('manageSettings', VatSettlement::class);
        $tenantId = $this->tenantId();
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('us_tax_jurisdictions', 'code')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:160'],
            'state' => ['required', 'string', 'size:2', 'alpha'],
            'level' => ['required', Rule::in(UsTaxJurisdiction::Levels)],
            'county' => ['nullable', 'string', 'max:80', 'required_if:level,county'],
            'city' => ['nullable', 'string', 'max:80', 'required_if:level,city'],
            'zip_prefix' => ['nullable', 'string', 'max:10', 'required_if:level,special'],
        ]);

        $jurisdiction = UsTaxJurisdiction::create([...$validated, 'state' => strtoupper($validated['state']), 'tenant_id' => $tenantId]);

        return response()->json(['data' => $jurisdiction], 201);
    }

    #[OA\Post(path: '/api/v1/general-ledger/us-tax/jurisdictions/{id}/rates', operationId: 'storeUsTaxRate', description: 'Adds a rate for a period to a jurisdiction. The previous open-ended rate ends the day before the new one starts.', summary: 'Add a US tax rate', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['rate_percent', 'effective_from'], properties: [
        new OA\Property(property: 'rate_percent', type: 'number', example: 7.25),
        new OA\Property(property: 'effective_from', type: 'string', format: 'date'),
        new OA\Property(property: 'effective_to', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'applies_to_shipping', type: 'boolean'),
    ])), tags: ['General Ledger Tax Settlements'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 201, description: 'Rate added', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Jurisdiction not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function storeRate(Request $request, string $id): JsonResponse
    {
        $this->authorize('manageSettings', VatSettlement::class);
        $tenantId = $this->tenantId();
        $jurisdiction = UsTaxJurisdiction::query()->where('tenant_id', $tenantId)->findOrFail($id);
        $validated = $request->validate([
            'rate_percent' => ['required', 'numeric', 'min:0', 'max:25'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'applies_to_shipping' => ['sometimes', 'boolean'],
        ]);

        $rate = UsTaxRate::query()->getConnection()->transaction(function () use ($validated, $jurisdiction, $tenantId): UsTaxRate {
            UsTaxRate::query()
                ->where('jurisdiction_id', $jurisdiction->id)
                ->whereNull('effective_to')
                ->whereDate('effective_from', '<', $validated['effective_from'])
                ->update(['effective_to' => date('Y-m-d', strtotime($validated['effective_from'].' -1 day'))]);

            return UsTaxRate::create([...$validated, 'jurisdiction_id' => $jurisdiction->id, 'tenant_id' => $tenantId]);
        });

        return response()->json(['data' => $rate], 201);
    }

    #[OA\Get(path: '/api/v1/general-ledger/us-tax/nexus', operationId: 'listUsTaxNexus', description: 'States where the active tenant has sales tax nexus.', summary: 'List US tax nexus', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Tax Settlements'], responses: [
        new OA\Response(response: 200, description: 'Nexus', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function nexus(): JsonResponse
    {
        $this->authorize('viewRegisters', VatSettlement::class);

        return response()->json(['data' => UsTaxNexus::query()->where('tenant_id', $this->tenantId())->orderBy('state')->orderBy('established_on')->get()]);
    }

    #[OA\Post(path: '/api/v1/general-ledger/us-tax/nexus', operationId: 'storeUsTaxNexus', description: 'Records nexus in a state from a date (physical presence or economic nexus by sales or transactions threshold).', summary: 'Add US tax nexus', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['state', 'nexus_type', 'established_on'], properties: [
        new OA\Property(property: 'state', type: 'string', example: 'TX'),
        new OA\Property(property: 'nexus_type', type: 'string', enum: ['physical', 'economic', 'affiliate', 'marketplace']),
        new OA\Property(property: 'established_on', type: 'string', format: 'date'),
        new OA\Property(property: 'ended_on', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'registration_number', type: 'string', nullable: true),
        new OA\Property(property: 'sales_threshold', type: 'number', nullable: true),
        new OA\Property(property: 'transactions_threshold', type: 'integer', nullable: true),
    ])), tags: ['General Ledger Tax Settlements'], responses: [
        new OA\Response(response: 201, description: 'Nexus added', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function storeNexus(Request $request): JsonResponse
    {
        $this->authorize('manageSettings', VatSettlement::class);
        $validated = $request->validate([
            'state' => ['required', 'string', 'size:2', 'alpha'],
            'nexus_type' => ['required', Rule::in(UsTaxNexus::Types)],
            'established_on' => ['required', 'date_format:Y-m-d'],
            'ended_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:established_on'],
            'registration_number' => ['nullable', 'string', 'max:60'],
            'sales_threshold' => ['nullable', 'numeric', 'min:0'],
            'transactions_threshold' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json(['data' => UsTaxNexus::create([...$validated, 'state' => strtoupper($validated['state']), 'tenant_id' => $this->tenantId()])], 201);
    }

    #[OA\Post(path: '/api/v1/general-ledger/us-tax/calculate', operationId: 'calculateUsSalesTax', description: 'Calculates the sales tax of a sale to a US destination: zero (reason no_nexus) without nexus in the state on the sale date, otherwise the sum of the rates in force of the state, county, city and special district jurisdictions that match the destination.', summary: 'Calculate US sales tax', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['state', 'taxable_amount'], properties: [
        new OA\Property(property: 'state', type: 'string', example: 'CA'),
        new OA\Property(property: 'date', type: 'string', format: 'date', description: 'Defaults to today'),
        new OA\Property(property: 'taxable_amount', type: 'number'),
        new OA\Property(property: 'shipping_amount', type: 'number'),
        new OA\Property(property: 'county', type: 'string', nullable: true),
        new OA\Property(property: 'city', type: 'string', nullable: true),
        new OA\Property(property: 'zip', type: 'string', nullable: true),
        new OA\Property(property: 'customer_id', description: 'Customer (contact) whose valid exemption certificate for the state exempts the sale', type: 'string', format: 'uuid', nullable: true),
    ])), tags: ['General Ledger Tax Settlements'], responses: [
        new OA\Response(response: 200, description: 'Tax by jurisdiction and total', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function calculate(Request $request): JsonResponse
    {
        $this->authorize('viewRegisters', VatSettlement::class);
        $validated = $request->validate([
            'state' => ['required', 'string', 'size:2', 'alpha'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'taxable_amount' => ['required', 'numeric', 'min:0'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0'],
            'county' => ['nullable', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:80'],
            'zip' => ['nullable', 'string', 'max:10'],
            'customer_id' => ['nullable', 'uuid'],
        ]);

        return response()->json(['data' => $this->salesTax->calculate(
            $this->tenantId(),
            $validated['state'],
            $validated['date'] ?? now()->toDateString(),
            (string) $validated['taxable_amount'],
            (string) ($validated['shipping_amount'] ?? '0'),
            $validated['county'] ?? null,
            $validated['city'] ?? null,
            $validated['zip'] ?? null,
            $validated['customer_id'] ?? null,
        )]);
    }

    #[OA\Put(path: '/api/v1/general-ledger/us-tax/jurisdictions/{id}', operationId: 'updateUsTaxJurisdiction', description: 'Updates a sales tax jurisdiction of the active tenant. The state and level cannot change once rates exist.', summary: 'Update a US tax jurisdiction', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 40),
        new OA\Property(property: 'name', type: 'string', maxLength: 160),
        new OA\Property(property: 'county', type: 'string', maxLength: 80, nullable: true),
        new OA\Property(property: 'city', type: 'string', maxLength: 80, nullable: true),
        new OA\Property(property: 'zip_prefix', type: 'string', maxLength: 10, nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ])), tags: ['General Ledger Tax Settlements'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function updateJurisdiction(Request $request, string $id): JsonResponse
    {
        $this->authorize('manageSettings', VatSettlement::class);
        $tenantId = $this->tenantId();
        $jurisdiction = UsTaxJurisdiction::query()->where('tenant_id', $tenantId)->findOrFail($id);
        $validated = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:40', Rule::unique('us_tax_jurisdictions', 'code')->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore($jurisdiction->id)],
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'county' => ['nullable', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:80'],
            'zip_prefix' => ['nullable', 'string', 'max:10'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $merged = [...$jurisdiction->only(['level', 'county', 'city', 'zip_prefix']), ...$validated];

        foreach (['county' => 'county', 'city' => 'city', 'special' => 'zip_prefix'] as $level => $field) {
            if ($jurisdiction->level === $level && blank($merged[$field] ?? null)) {
                throw ValidationException::withMessages([$field => [__('validation.required', ['attribute' => $field])]]);
            }
        }

        $jurisdiction->update($validated);

        return response()->json(['data' => $jurisdiction->fresh('rates')]);
    }

    #[OA\Delete(path: '/api/v1/general-ledger/us-tax/jurisdictions/{id}', operationId: 'deleteUsTaxJurisdiction', description: 'Deletes a sales tax jurisdiction and its rates (soft delete).', summary: 'Delete a US tax jurisdiction', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Tax Settlements'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 204, description: 'Deleted'),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function destroyJurisdiction(string $id): Response
    {
        $this->authorize('manageSettings', VatSettlement::class);
        $tenantId = $this->tenantId();
        $jurisdiction = UsTaxJurisdiction::query()->where('tenant_id', $tenantId)->findOrFail($id);

        $jurisdiction->getConnection()->transaction(function () use ($jurisdiction, $tenantId): void {
            UsTaxRate::query()->where('tenant_id', $tenantId)->where('jurisdiction_id', $jurisdiction->id)->get()->each->delete();
            $jurisdiction->delete();
        });

        return response()->noContent();
    }

    #[OA\Put(path: '/api/v1/general-ledger/us-tax/rates/{id}', operationId: 'updateUsTaxRate', description: 'Updates a rate: percentage, validity dates and shipping flag.', summary: 'Update a US tax rate', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'rate_percent', type: 'number', minimum: 0, maximum: 25),
        new OA\Property(property: 'effective_from', type: 'string', format: 'date'),
        new OA\Property(property: 'effective_to', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'applies_to_shipping', type: 'boolean'),
    ])), tags: ['General Ledger Tax Settlements'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function updateRate(Request $request, string $id): JsonResponse
    {
        $this->authorize('manageSettings', VatSettlement::class);
        $rate = UsTaxRate::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $validated = $request->validate([
            'rate_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:25'],
            'effective_from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d'],
            'applies_to_shipping' => ['sometimes', 'boolean'],
        ]);
        $from = $validated['effective_from'] ?? $rate->effective_from->toDateString();
        $to = array_key_exists('effective_to', $validated) ? $validated['effective_to'] : $rate->effective_to?->toDateString();

        if ($to !== null && $to < $from) {
            throw ValidationException::withMessages(['effective_to' => [__('validation.after_or_equal', ['attribute' => 'effective_to', 'date' => 'effective_from'])]]);
        }

        $rate->update($validated);

        return response()->json(['data' => $rate->fresh()]);
    }

    #[OA\Delete(path: '/api/v1/general-ledger/us-tax/rates/{id}', operationId: 'deleteUsTaxRate', description: 'Deletes a rate of a jurisdiction (soft delete).', summary: 'Delete a US tax rate', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Tax Settlements'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 204, description: 'Deleted'),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function destroyRate(string $id): Response
    {
        $this->authorize('manageSettings', VatSettlement::class);
        UsTaxRate::query()->where('tenant_id', $this->tenantId())->findOrFail($id)->delete();

        return response()->noContent();
    }

    #[OA\Put(path: '/api/v1/general-ledger/us-tax/nexus/{id}', operationId: 'updateUsTaxNexus', description: 'Updates a nexus record.', summary: 'Update a US tax nexus', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'nexus_type', type: 'string', enum: ['physical', 'economic', 'affiliate', 'marketplace']),
        new OA\Property(property: 'established_on', type: 'string', format: 'date'),
        new OA\Property(property: 'ended_on', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'registration_number', type: 'string', maxLength: 60, nullable: true),
        new OA\Property(property: 'sales_threshold', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'transactions_threshold', type: 'integer', minimum: 0, nullable: true),
    ])), tags: ['General Ledger Tax Settlements'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function updateNexus(Request $request, string $id): JsonResponse
    {
        $this->authorize('manageSettings', VatSettlement::class);
        $nexus = UsTaxNexus::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $validated = $request->validate([
            'nexus_type' => ['sometimes', 'required', Rule::in(UsTaxNexus::Types)],
            'established_on' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'ended_on' => ['nullable', 'date_format:Y-m-d'],
            'registration_number' => ['nullable', 'string', 'max:60'],
            'sales_threshold' => ['nullable', 'numeric', 'min:0'],
            'transactions_threshold' => ['nullable', 'integer', 'min:0'],
        ]);
        $from = $validated['established_on'] ?? $nexus->established_on->toDateString();
        $to = array_key_exists('ended_on', $validated) ? $validated['ended_on'] : $nexus->ended_on?->toDateString();

        if ($to !== null && $to < $from) {
            throw ValidationException::withMessages(['ended_on' => [__('validation.after_or_equal', ['attribute' => 'ended_on', 'date' => 'established_on'])]]);
        }

        $nexus->update($validated);

        return response()->json(['data' => $nexus->fresh()]);
    }

    #[OA\Delete(path: '/api/v1/general-ledger/us-tax/nexus/{id}', operationId: 'deleteUsTaxNexus', description: 'Deletes a nexus record (soft delete).', summary: 'Delete a US tax nexus', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Tax Settlements'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 204, description: 'Deleted'),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function destroyNexus(string $id): Response
    {
        $this->authorize('manageSettings', VatSettlement::class);
        UsTaxNexus::query()->where('tenant_id', $this->tenantId())->findOrFail($id)->delete();

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/general-ledger/us-tax/preload', operationId: 'preloadUsTaxRates', description: 'Loads the state jurisdictions and base sales tax rates of the seed set (CA, NY, TX, FL, WA, IL, PA, OH, OR) for the active tenant. Idempotent: existing jurisdictions are skipped. County, city and district rates are not in the seed.', summary: 'Preload US state sales tax rates', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'states', description: 'Restrict the preload to these states', type: 'array', items: new OA\Items(type: 'string', maxLength: 2)),
    ])), tags: ['General Ledger Tax Settlements'], responses: [
        new OA\Response(response: 200, description: 'Counts of the jurisdictions created and skipped', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'created', type: 'integer'), new OA\Property(property: 'skipped', type: 'integer')])])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function preload(Request $request): JsonResponse
    {
        $this->authorize('manageSettings', VatSettlement::class);
        $validated = $request->validate([
            'states' => ['nullable', 'array'],
            'states.*' => ['string', 'size:2', Rule::in(array_keys(UsSalesTaxRateCatalog::states()))],
        ]);

        return response()->json(['data' => $this->salesTax->preload($this->tenantId(), $validated['states'] ?? null)]);
    }

    #[OA\Get(path: '/api/v1/general-ledger/us-tax/lookup', operationId: 'lookupUsTaxRate', description: 'Combined sales tax rate in force for a destination, with the jurisdictions that make it up. The state comes from `state` or, when absent, from the ZIP code (states of the seed set only). County, city and the ZIP prefix select the county, city and special district jurisdictions. Nexus is reported but not required.', summary: 'Look up the sales tax rate of a state or ZIP code', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Tax Settlements'], parameters: [
        new OA\Parameter(name: 'state', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 2)),
        new OA\Parameter(name: 'zip', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 10)),
        new OA\Parameter(name: 'county', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 80)),
        new OA\Parameter(name: 'city', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 80)),
        new OA\Parameter(name: 'date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
    ], responses: [
        new OA\Response(response: 200, description: 'Rates in force', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Neither a state nor a known ZIP code', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewRegisters', VatSettlement::class);
        $validated = $request->validate([
            'state' => ['nullable', 'string', 'size:2', 'alpha', 'required_without:zip'],
            'zip' => ['nullable', 'string', 'max:10', 'required_without:state'],
            'county' => ['nullable', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:80'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $state = isset($validated['state']) ? strtoupper($validated['state']) : UsSalesTaxRateCatalog::stateOfZip((string) $validated['zip']);

        if ($state === null) {
            throw ValidationException::withMessages(['zip' => [__('validation.us_zip_unknown')]]);
        }

        return response()->json(['data' => [
            ...$this->salesTax->lookup($this->tenantId(), $state, $validated['date'] ?? now()->toDateString(), $validated['county'] ?? null, $validated['city'] ?? null, $validated['zip'] ?? null),
            'zip' => $validated['zip'] ?? null,
        ]]);
    }

    private function tenantId(): string
    {
        $tenantId = $this->tenantContext->getTenantId();
        abort_if($tenantId === null, 403, 'Missing or unresolvable tenant context');

        return (string) $tenantId;
    }
}
