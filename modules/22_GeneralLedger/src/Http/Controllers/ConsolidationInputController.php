<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\GeneralLedger\Http\Requests\StoreConsolidationAccountMappingRequest;
use Modules\GeneralLedger\Http\Requests\StoreConsolidationAcquisitionRequest;
use Modules\GeneralLedger\Http\Requests\StoreGoodwillImpairmentRequest;
use Modules\GeneralLedger\Http\Requests\StoreIntragroupDividendRequest;
use Modules\GeneralLedger\Http\Requests\StoreIntragroupTransactionRequest;
use Modules\GeneralLedger\Http\Resources\ConsolidationInputResource;
use Modules\GeneralLedger\Models\ConsolidationAccountMapping;
use Modules\GeneralLedger\Models\ConsolidationAcquisition;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Models\IntragroupDividend;
use Modules\GeneralLedger\Models\IntragroupTransaction;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationInputService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The inputs of the consolidation that come from the user: account mappings to the group chart, acquisitions with
 * fair-value adjustments and goodwill impairments, intragroup transactions with unrealised margin, intragroup dividends.
 * Amounts are in group currency. How a run uses each one is documented in ConsolidationCalculator.
 */
final class ConsolidationInputController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ConsolidationInputService $inputs) {}

    #[OA\Get(
        path: '/api/v1/general-ledger/consolidation/groups/{id}/account-mappings',
        operationId: 'listConsolidationAccountMappings',
        description: 'Mappings of the accounts of the members to account codes of the group chart. An account without a mapping is consolidated under its own code.',
        summary: 'List account mappings of a group',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Mappings', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerConsolidationInput'))])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function indexAccountMappings(string $id): JsonResponse
    {
        $group = ConsolidationGroup::query()->findOrFail($id);

        return $this->successResponse(ConsolidationInputResource::collection(ConsolidationAccountMapping::query()->where('group_id', $group->id)->orderBy('member_tenant_id')->orderBy('group_account_code')->get()));
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/consolidation/groups/{id}/account-mappings',
        operationId: 'createConsolidationAccountMapping',
        description: 'Maps an account of a member to a group account code (and name). The account keeps its own type, so its translation rate does not change. When the group requires mappings, a run refuses accounts with a balance that are not mapped.',
        summary: 'Map an account to the group chart',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreConsolidationAccountMappingRequest')),
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Mapping created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerConsolidationInput')])),
            new OA\Response(response: 403, description: 'The ledger of the entity is not accessible', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The account is already mapped', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function storeAccountMapping(StoreConsolidationAccountMappingRequest $request, string $id): JsonResponse
    {
        $group = ConsolidationGroup::query()->findOrFail($id);
        $mapping = $this->inputs->addAccountMapping($request->user(), $group, $request->validated('member_tenant_id'), $request->validated('account_id'), $request->validated('group_account_code'), $request->validated('group_account_name'));

        return $this->successResponse(new ConsolidationInputResource($mapping), 'Account mapping created', Response::HTTP_CREATED);
    }

    #[OA\Delete(
        path: '/api/v1/general-ledger/consolidation/account-mappings/{mappingId}',
        operationId: 'deleteConsolidationAccountMapping',
        description: 'Deletes an account mapping of the group chart.',
        summary: 'Delete an account mapping',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'mappingId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroyAccountMapping(string $mappingId): JsonResponse
    {
        ConsolidationAccountMapping::query()->findOrFail($mappingId)->forceDelete();

        return $this->successResponse(null, 'Account mapping deleted');
    }

    #[OA\Get(
        path: '/api/v1/general-ledger/consolidation/groups/{id}/acquisitions',
        operationId: 'listConsolidationAcquisitions',
        description: 'Acquisition records of the group with their fair-value adjustments and goodwill impairments.',
        summary: 'List acquisitions',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Acquisitions', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerConsolidationInput'))])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function indexAcquisitions(string $id): JsonResponse
    {
        $group = ConsolidationGroup::query()->findOrFail($id);

        return $this->successResponse(ConsolidationInputResource::collection(ConsolidationAcquisition::query()->where('group_id', $group->id)->with(['fairValueAdjustments', 'impairments'])->orderBy('acquisition_date')->get()));
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/consolidation/groups/{id}/acquisitions',
        operationId: 'createConsolidationAcquisition',
        description: 'Records the first consolidation of a member: acquisition date, cost, book net assets at that date (group currency), fair-value adjustments by account of the group chart and, when `recognise_deferred_tax` is true, the deferred tax rate to apply to them (an input: no rate is assumed). A run then computes the goodwill or negative goodwill; its amortisation follows the goodwill settings of the group.',
        summary: 'Record an acquisition',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreConsolidationAcquisitionRequest')),
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Acquisition recorded', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerConsolidationInput')])),
            new OA\Response(response: 403, description: 'The ledger of the entity is not accessible', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The member already has an acquisition record', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function storeAcquisition(StoreConsolidationAcquisitionRequest $request, string $id): JsonResponse
    {
        $group = ConsolidationGroup::query()->findOrFail($id);

        return $this->successResponse(new ConsolidationInputResource($this->inputs->addAcquisition($request->user(), $group, $request->validated())), 'Acquisition recorded', Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/consolidation/acquisitions/{acquisitionId}/impairments',
        operationId: 'createConsolidationGoodwillImpairment',
        description: 'Enters the impairment of the goodwill charged in a fiscal year (the year of the start of the parent fiscal year), in group currency. Used when the goodwill method of the group is straight_line or impairment_only.',
        summary: 'Enter a goodwill impairment',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreGoodwillImpairmentRequest')),
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'acquisitionId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Impairment entered', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerConsolidationInput')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The year already has an impairment', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function storeImpairment(StoreGoodwillImpairmentRequest $request, string $acquisitionId): JsonResponse
    {
        $acquisition = ConsolidationAcquisition::query()->findOrFail($acquisitionId);

        return $this->successResponse(new ConsolidationInputResource($this->inputs->addImpairment($acquisition, (int) $request->validated('fiscal_year'), (string) $request->validated('amount'))), 'Impairment entered', Response::HTTP_CREATED);
    }

    #[OA\Delete(
        path: '/api/v1/general-ledger/consolidation/acquisitions/{acquisitionId}',
        operationId: 'deleteConsolidationAcquisition',
        description: 'Deletes an acquisition record with its adjustments and impairments. Runs already made keep the inputs they used.',
        summary: 'Delete an acquisition',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'acquisitionId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroyAcquisition(string $acquisitionId): JsonResponse
    {
        $this->inputs->deleteAcquisition(ConsolidationAcquisition::query()->findOrFail($acquisitionId));

        return $this->successResponse(null, 'Acquisition deleted');
    }

    #[OA\Get(
        path: '/api/v1/general-ledger/consolidation/groups/{id}/intragroup-transactions',
        operationId: 'listIntragroupTransactions',
        description: 'Intragroup sales of inventory or fixed assets declared for the elimination of unrealised profit.',
        summary: 'List intragroup transactions',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Transactions', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerConsolidationInput'))])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function indexTransactions(string $id): JsonResponse
    {
        $group = ConsolidationGroup::query()->findOrFail($id);

        return $this->successResponse(ConsolidationInputResource::collection(IntragroupTransaction::query()->where('group_id', $group->id)->orderBy('transaction_date')->get()));
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/consolidation/groups/{id}/intragroup-transactions',
        operationId: 'createIntragroupTransaction',
        description: 'Declares a sale of inventory or of a fixed asset between two members, with the unrealised margin (or sale price and seller cost) in group currency and the share still in stock at the period end. A run eliminates the unrealised profit from the asset of the buyer; for a fixed asset it also reverses the excess depreciation over the useful life. Optionally tied to an intercompany relation of the group.',
        summary: 'Declare an intragroup transaction',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreIntragroupTransactionRequest')),
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Transaction declared', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerConsolidationInput')])),
            new OA\Response(response: 403, description: 'The ledger of an entity is not accessible', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function storeTransaction(StoreIntragroupTransactionRequest $request, string $id): JsonResponse
    {
        $group = ConsolidationGroup::query()->findOrFail($id);
        $data = $request->validated();
        $data['remaining_percentage'] ??= '100';

        return $this->successResponse(new ConsolidationInputResource($this->inputs->addTransaction($request->user(), $group, $data)), 'Intragroup transaction declared', Response::HTTP_CREATED);
    }

    #[OA\Delete(
        path: '/api/v1/general-ledger/consolidation/intragroup-transactions/{transactionId}',
        operationId: 'deleteIntragroupTransaction',
        description: 'Deletes a declared intragroup transaction. Runs already made keep the inputs they used.',
        summary: 'Delete an intragroup transaction',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'transactionId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroyTransaction(string $transactionId): JsonResponse
    {
        IntragroupTransaction::query()->findOrFail($transactionId)->forceDelete();

        return $this->successResponse(null, 'Intragroup transaction deleted');
    }

    #[OA\Get(
        path: '/api/v1/general-ledger/consolidation/groups/{id}/intragroup-dividends',
        operationId: 'listIntragroupDividends',
        description: 'Dividends between members declared for their elimination.',
        summary: 'List intragroup dividends',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Dividends', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerConsolidationInput'))])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function indexDividends(string $id): JsonResponse
    {
        $group = ConsolidationGroup::query()->findOrFail($id);

        return $this->successResponse(ConsolidationInputResource::collection(IntragroupDividend::query()->where('group_id', $group->id)->orderBy('dividend_date')->get()));
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/consolidation/groups/{id}/intragroup-dividends',
        operationId: 'createIntragroupDividend',
        description: 'Declares a dividend that a member received from another one, in group currency, with the income account of the receiver and the equity account of the payer it was charged to. A run eliminates the dividends of its fiscal year, up to the income booked.',
        summary: 'Declare an intragroup dividend',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreIntragroupDividendRequest')),
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Dividend declared', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerConsolidationInput')])),
            new OA\Response(response: 403, description: 'The ledger of an entity is not accessible', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function storeDividend(StoreIntragroupDividendRequest $request, string $id): JsonResponse
    {
        $group = ConsolidationGroup::query()->findOrFail($id);

        return $this->successResponse(new ConsolidationInputResource($this->inputs->addDividend($request->user(), $group, $request->validated())), 'Intragroup dividend declared', Response::HTTP_CREATED);
    }

    #[OA\Delete(
        path: '/api/v1/general-ledger/consolidation/intragroup-dividends/{dividendId}',
        operationId: 'deleteIntragroupDividend',
        description: 'Deletes a declared intragroup dividend. Runs already made keep the inputs they used.',
        summary: 'Delete an intragroup dividend',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'dividendId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroyDividend(string $dividendId): JsonResponse
    {
        IntragroupDividend::query()->findOrFail($dividendId)->forceDelete();

        return $this->successResponse(null, 'Intragroup dividend deleted');
    }
}
