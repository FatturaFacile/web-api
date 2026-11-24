<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Manufacturing\Http\Controllers\Concerns\InteractsWithManufacturing;
use Modules\Manufacturing\Http\Requests\UpdateAccountingSettingsRequest;
use Modules\Manufacturing\Services\ManufacturingAccountingService;
use Modules\Manufacturing\Services\ManufacturingMasterDataService;
use OpenApi\Attributes as OA;

/**
 * Ledger accounts of the manufacturing postings.
 */
final class ManufacturingSettingsController extends Controller
{
    use InteractsWithManufacturing;

    public function __construct(
        private readonly ManufacturingMasterDataService $masterData,
        private readonly ManufacturingAccountingService $accounting,
    ) {}

    /**
     * Account codes in force.
     */
    #[OA\Get(path: '/api/v1/manufacturing/settings/accounting', operationId: 'getMfgAccountingSettings', description: 'Ledger account codes used by the manufacturing postings (configured ones, else the module defaults).', summary: 'Get manufacturing accounts', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Settings'],
        responses: [new OA\Response(response: 200, description: 'Account codes', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgAccountingSettings')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401)])]
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->codes()]);
    }

    /**
     * Changes the account codes.
     */
    #[OA\Put(path: '/api/v1/manufacturing/settings/accounting', operationId: 'updateMfgAccountingSettings', description: 'Changes the ledger account codes of the manufacturing postings (owners and administrators only); null goes back to the module default. Postings already made are not changed.', summary: 'Update manufacturing accounts', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Settings'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgUpdateAccountingSettingsRequest')),
        responses: [new OA\Response(response: 200, description: 'Account codes', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgAccountingSettings')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function update(UpdateAccountingSettingsRequest $request): JsonResponse
    {
        $settings = $this->masterData->accountingSettings($this->tenantId());
        $this->authorize('update', $settings);

        /** @var array<string, string|null> $codes */
        $codes = $request->validated();
        $this->masterData->updateAccountingSettings($settings, $codes, $this->actor($request));

        return response()->json(['data' => $this->codes()]);
    }

    /**
     * Account codes in force, keyed as the request fields.
     *
     * @return array<string, string>
     */
    private function codes(): array
    {
        $codes = [];
        foreach ($this->accounting->accountCodes($this->tenantId()) as $role => $code) {
            $codes[$role.'_account_code'] = $code;
        }

        return $codes;
    }
}
