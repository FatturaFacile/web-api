<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Contracts\Enums\ContractKind;
use Modules\Contracts\Http\Requests\GiveContractNoticeRequest;
use Modules\Contracts\Http\Requests\RenewContractRequest;
use Modules\Contracts\Http\Requests\StoreContractRequest;
use Modules\Contracts\Http\Requests\UpdateContractTermsRequest;
use Modules\Contracts\Http\Resources\ContractAlertResource;
use Modules\Contracts\Http\Resources\ContractResource;
use Modules\Contracts\Models\ContractAlert;
use Modules\Contracts\Services\ContractRegister;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP endpoints of the contract register flows of the Contracts module.
 */
final class ContractRegisterController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly ContractRegister $register) {}

    /**
     * List contracts.
     */
    #[OA\Get(
        path: '/api/v1/contract-register',
        operationId: 'listContractRegister',
        description: 'Customer and supplier contracts in one list, soonest end date first.',
        summary: 'List contracts',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Contract Register'],
        parameters: [
            new OA\Parameter(name: 'kind', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['customer', 'supplier'])),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'expiring_within_days', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Contracts', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ContractResource'))])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $contracts = $this->register->paginate(
            $request->only(['kind', 'status', 'expiring_within_days']),
            min(100, max(5, (int) $request->query('per_page', 25)))
        );

        return $this->successResponse(
            ContractResource::collection(collect($contracts->items())),
            'Contracts',
            meta: ['current_page' => $contracts->currentPage(), 'last_page' => $contracts->lastPage(), 'per_page' => $contracts->perPage(), 'total' => $contracts->total()]
        );
    }

    /**
     * Create contract.
     */
    #[OA\Post(
        path: '/api/v1/contract-register',
        operationId: 'createContract',
        description: 'Create a customer contract (a service contract) or a supplier contract (a framework agreement) with its terms.',
        summary: 'Create contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StoreContractRequest')),
        tags: ['Contract Register'],
        responses: [
            new OA\Response(response: 201, description: 'Contract created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractResource')])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreContractRequest $request): JsonResponse
    {
        $contract = $this->register->create(ContractKind::from($request->validated('kind')), $request->validated());

        return $this->successResponse(new ContractResource($contract->refresh()), 'Contract created', Response::HTTP_CREATED);
    }

    /**
     * Show contract.
     */
    #[OA\Get(
        path: '/api/v1/contract-register/{kind}/{id}',
        operationId: 'getContract',
        description: 'A contract of the register.',
        summary: 'Show contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Contract Register'],
        parameters: [
            new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['customer', 'supplier'])),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Contract', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $kind, string $id): JsonResponse
    {
        return $this->successResponse(new ContractResource($this->register->find(ContractKind::from($kind), $id)));
    }

    /**
     * Update contract terms.
     */
    #[OA\Put(
        path: '/api/v1/contract-register/{kind}/{id}/terms',
        operationId: 'updateContractTerms',
        description: 'Change renewal and notice terms. The notice deadline follows.',
        summary: 'Update contract terms',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/UpdateContractTermsRequest')),
        tags: ['Contract Register'],
        parameters: [
            new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['customer', 'supplier'])),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Terms updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function updateTerms(UpdateContractTermsRequest $request, string $kind, string $id): JsonResponse
    {
        $contractKind = ContractKind::from($kind);
        $contract = $this->register->updateTerms($contractKind, $this->register->find($contractKind, $id), $request->validated());

        return $this->successResponse(new ContractResource($contract->refresh()), 'Terms updated');
    }

    /**
     * Renew contract.
     */
    #[OA\Post(
        path: '/api/v1/contract-register/{kind}/{id}/renew',
        operationId: 'renewContract',
        description: 'Extend a contract by its renewal term or to a chosen date. The renewal is recorded and given notice is cleared.',
        summary: 'Renew contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/RenewContractRequest')),
        tags: ['Contract Register'],
        parameters: [
            new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['customer', 'supplier'])),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Renewed', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'No end date or no term', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function renew(RenewContractRequest $request, string $kind, string $id): JsonResponse
    {
        $contractKind = ContractKind::from($kind);
        $contract = $this->register->renew($contractKind, $this->register->find($contractKind, $id), $request->validated('new_end_date'), 'manual', (string) $request->user()->id);

        return $this->successResponse(new ContractResource($contract->refresh()), 'Contract renewed');
    }

    /**
     * Give notice.
     */
    #[OA\Post(
        path: '/api/v1/contract-register/{kind}/{id}/notice',
        operationId: 'giveContractNotice',
        description: 'Record that notice was given, which stops the automatic renewal.',
        summary: 'Give notice',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Contract Register'],
        parameters: [
            new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['customer', 'supplier'])),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'notice_given_at', type: 'string', format: 'date', nullable: true, description: 'Day the notice was given; today when absent, never in the future.')])),
        responses: [
            new OA\Response(response: 200, description: 'Notice recorded', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function giveNotice(GiveContractNoticeRequest $request, string $kind, string $id): JsonResponse
    {
        $givenAt = $request->date('notice_given_at');
        $contract = $this->register->giveNotice($this->register->find(ContractKind::from($kind), $id), $givenAt === null ? null : CarbonImmutable::instance($givenAt));

        return $this->successResponse(new ContractResource($contract->refresh()), 'Notice recorded');
    }

    /**
     * List contract alerts.
     */
    #[OA\Get(
        path: '/api/v1/contract-register/alerts',
        operationId: 'listContractAlerts',
        description: 'Notice and expiry alerts, open ones by default.',
        summary: 'List contract alerts',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Contract Register'],
        parameters: [new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'acknowledged']))],
        responses: [
            new OA\Response(response: 200, description: 'Alerts', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ContractAlertResource'))])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function alerts(Request $request): JsonResponse
    {
        return $this->successResponse(ContractAlertResource::collection(
            ContractAlert::query()->where('status', (string) $request->query('status', 'open'))->orderBy('due_on')->get()
        ));
    }

    /**
     * Acknowledge alert.
     */
    #[OA\Post(
        path: '/api/v1/contract-register/alerts/{id}/acknowledge',
        operationId: 'acknowledgeContractAlert',
        description: 'Mark an alert as seen.',
        summary: 'Acknowledge alert',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Contract Register'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Acknowledged', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractAlertResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function acknowledgeAlert(Request $request, string $id): JsonResponse
    {
        $alert = ContractAlert::query()->findOrFail($id);
        $alert->update(['status' => 'acknowledged', 'acknowledged_by' => $request->user()->id, 'acknowledged_at' => now()]);

        return $this->successResponse(new ContractAlertResource($alert), 'Alert acknowledged');
    }
}
