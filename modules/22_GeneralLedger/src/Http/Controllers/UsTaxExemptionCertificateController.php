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
use Modules\GeneralLedger\Models\UsTaxExemptionCertificate;
use Modules\GeneralLedger\Models\UsTaxJurisdiction;
use Modules\GeneralLedger\Models\VatSettlement;
use OpenApi\Attributes as OA;

/**
 * Sales tax exemption certificates of customers: valid in a state (or in one of its jurisdictions) between the issue
 * and the expiry date, and applied by the sales tax calculation when a customer is given.
 */
final class UsTaxExemptionCertificateController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(path: '/api/v1/general-ledger/us-tax/exemption-certificates', operationId: 'listUsTaxExemptionCertificates', description: 'Exemption certificates of the active tenant. Filters: contact_id, state.', summary: 'List US tax exemption certificates', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Tax Settlements'], parameters: [
        new OA\Parameter(name: 'contact_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'state', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 2)),
    ], responses: [
        new OA\Response(response: 200, description: 'Certificates', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/UsTaxExemptionCertificate'))])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewRegisters', VatSettlement::class);

        return response()->json(['data' => UsTaxExemptionCertificate::query()
            ->where('tenant_id', $this->tenantId())
            ->when($request->filled('contact_id'), static fn ($query) => $query->where('contact_id', (string) $request->query('contact_id')))
            ->when($request->filled('state'), static fn ($query) => $query->where('state', strtoupper((string) $request->query('state'))))
            ->orderBy('state')
            ->orderBy('issued_on')
            ->get()]);
    }

    #[OA\Post(path: '/api/v1/general-ledger/us-tax/exemption-certificates', operationId: 'createUsTaxExemptionCertificate', description: 'Records an exemption certificate of a customer for a state, optionally for one jurisdiction of it.', summary: 'Create a US tax exemption certificate', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['contact_id', 'state', 'certificate_number', 'issued_on'], properties: [
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'state', type: 'string', maxLength: 2, example: 'CA'),
        new OA\Property(property: 'jurisdiction_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'certificate_number', type: 'string', maxLength: 60),
        new OA\Property(property: 'reason', type: 'string', enum: ['resale', 'government', 'nonprofit', 'manufacturing', 'agricultural', 'other']),
        new OA\Property(property: 'issued_on', type: 'string', format: 'date'),
        new OA\Property(property: 'expires_on', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'notes', type: 'string', maxLength: 255, nullable: true),
    ])), tags: ['General Ledger Tax Settlements'], responses: [
        new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/UsTaxExemptionCertificate')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('manageSettings', VatSettlement::class);
        $tenantId = $this->tenantId();
        $validated = $request->validate([
            'contact_id' => ['required', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'state' => ['required', 'string', 'size:2', 'alpha'],
            'jurisdiction_id' => ['nullable', 'uuid', Rule::exists('us_tax_jurisdictions', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'certificate_number' => ['required', 'string', 'max:60'],
            'reason' => ['sometimes', Rule::in(UsTaxExemptionCertificate::Reasons)],
            'issued_on' => ['required', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issued_on'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $validated['state'] = strtoupper($validated['state']);
        $this->assertJurisdictionInState($validated['jurisdiction_id'] ?? null, $validated['state'], $tenantId);

        return response()->json(['data' => UsTaxExemptionCertificate::create([...$validated, 'tenant_id' => $tenantId])], 201);
    }

    #[OA\Put(path: '/api/v1/general-ledger/us-tax/exemption-certificates/{id}', operationId: 'updateUsTaxExemptionCertificate', description: 'Updates an exemption certificate (number, reason, dates, active flag, notes, jurisdiction).', summary: 'Update a US tax exemption certificate', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'jurisdiction_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'certificate_number', type: 'string', maxLength: 60),
        new OA\Property(property: 'reason', type: 'string', enum: ['resale', 'government', 'nonprofit', 'manufacturing', 'agricultural', 'other']),
        new OA\Property(property: 'issued_on', type: 'string', format: 'date'),
        new OA\Property(property: 'expires_on', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'notes', type: 'string', maxLength: 255, nullable: true),
    ])), tags: ['General Ledger Tax Settlements'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/UsTaxExemptionCertificate')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorize('manageSettings', VatSettlement::class);
        $tenantId = $this->tenantId();
        $certificate = UsTaxExemptionCertificate::query()->where('tenant_id', $tenantId)->findOrFail($id);
        $validated = $request->validate([
            'jurisdiction_id' => ['nullable', 'uuid', Rule::exists('us_tax_jurisdictions', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'certificate_number' => ['sometimes', 'required', 'string', 'max:60'],
            'reason' => ['sometimes', Rule::in(UsTaxExemptionCertificate::Reasons)],
            'issued_on' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $issued = $validated['issued_on'] ?? $certificate->issued_on->toDateString();
        $expires = array_key_exists('expires_on', $validated) ? $validated['expires_on'] : $certificate->expires_on?->toDateString();

        if ($expires !== null && $expires < $issued) {
            throw ValidationException::withMessages(['expires_on' => [__('validation.after_or_equal', ['attribute' => 'expires_on', 'date' => 'issued_on'])]]);
        }

        $this->assertJurisdictionInState($validated['jurisdiction_id'] ?? null, (string) $certificate->state, $tenantId);
        $certificate->update($validated);

        return response()->json(['data' => $certificate->fresh()]);
    }

    #[OA\Delete(path: '/api/v1/general-ledger/us-tax/exemption-certificates/{id}', operationId: 'deleteUsTaxExemptionCertificate', description: 'Deletes an exemption certificate (soft delete).', summary: 'Delete a US tax exemption certificate', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Tax Settlements'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 204, description: 'Deleted'),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function destroy(string $id): Response
    {
        $this->authorize('manageSettings', VatSettlement::class);
        UsTaxExemptionCertificate::query()->where('tenant_id', $this->tenantId())->findOrFail($id)->delete();

        return response()->noContent();
    }

    private function assertJurisdictionInState(?string $jurisdictionId, string $state, string $tenantId): void
    {
        if ($jurisdictionId !== null && ! UsTaxJurisdiction::query()->where('tenant_id', $tenantId)->where('state', $state)->whereKey($jurisdictionId)->exists()) {
            throw ValidationException::withMessages(['jurisdiction_id' => [__('validation.us_jurisdiction_state_mismatch')]]);
        }
    }

    private function tenantId(): string
    {
        $tenantId = $this->tenantContext->getTenantId();
        abort_if($tenantId === null, 403, 'Missing or unresolvable tenant context');

        return (string) $tenantId;
    }
}
