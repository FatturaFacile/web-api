<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\GeneralLedger\Http\Requests\UpdateVatTaxSettingRequest;
use Modules\GeneralLedger\Http\Resources\VatTaxSettingResource;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Services\Tax\VatAccountResolver;
use OpenApi\Attributes as OA;

final class VatTaxSettingController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly VatAccountResolver $accountResolver
    ) {}

    /**
     * Returns the VAT settings of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/tax-settings',
        operationId: 'getGeneralLedgerTaxSettings',
        description: 'Returns the VAT settings of the active tenant: ledger accounts of the settlement, filing period and LIPE declarant. Empty account fields fall back to the default account codes.',
        summary: 'Get VAT settings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerVatTaxSettingResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(): JsonResponse
    {
        $this->authorize('manageSettings', VatSettlement::class);

        return response()->json([
            'data' => new VatTaxSettingResource($this->accountResolver->settingsOf($this->tenantId())),
        ]);
    }

    /**
     * Updates the VAT settings of the active tenant.
     */
    #[OA\Put(
        path: '/api/v1/general-ledger/tax-settings',
        operationId: 'updateGeneralLedgerTaxSettings',
        description: 'Updates the VAT settings of the active tenant. Accounts must belong to the tenant.',
        summary: 'Update VAT settings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerUpdateVatTaxSettingRequest')),
        tags: ['General Ledger Tax Settlements'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerVatTaxSettingResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function update(UpdateVatTaxSettingRequest $request): JsonResponse
    {
        $this->authorize('manageSettings', VatSettlement::class);

        $settings = $this->accountResolver->settingsOf($this->tenantId());
        $settings->fill($request->validated());
        $settings->save();

        return response()->json([
            'message' => 'VAT settings updated successfully',
            'data' => new VatTaxSettingResource($settings),
        ]);
    }

    private function tenantId(): string
    {
        $tenantId = $this->tenantContext->getTenantId();

        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        return $tenantId;
    }
}
