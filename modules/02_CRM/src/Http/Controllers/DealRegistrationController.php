<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\CRM\Http\Requests\DecisionReasonRequest;
use Modules\CRM\Http\Requests\SubmitDealRegistrationRequest;
use Modules\CRM\Models\DealRegistration;
use Modules\CRM\Services\DealRegistrationService;
use OpenApi\Attributes as OA;

/**
 * Deal registration of partners.
 */
final class DealRegistrationController extends CrmExtensionController
{
    public function __construct(private readonly DealRegistrationService $registrations) {}

    #[OA\Get(path: '/api/v1/crm/deal-registrations', operationId: 'listCrmDealRegistrations', description: 'Deal registrations, latest first. Filters: status, partner_id.', summary: 'List deal registrations', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Partners'], responses: [
        new OA\Response(response: 200, description: 'Records', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CrmDealRegistration'))])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['status' => ['nullable', 'string'], 'partner_id' => ['nullable', 'uuid']]);

        return $this->paginated($this->scoped(DealRegistration::class)->with('partner:id,name')
            ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['partner_id']), static fn ($query) => $query->where('partner_id', $filters['partner_id']))
            ->orderByDesc('registered_on'));
    }

    #[OA\Post(path: '/api/v1/crm/deal-registrations', operationId: 'createCrmDealRegistration', description: 'A partner registers a deal. A live approved registration of the same customer is reported in conflicts_with_id and blocks the approval.', summary: 'Register a deal', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmDealRegistrationRequest')), tags: ['CRM Partners'], responses: [
        new OA\Response(response: 201, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmDealRegistration')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function store(SubmitDealRegistrationRequest $request): JsonResponse
    {
        $this->authorize('create', DealRegistration::class);

        return $this->present($this->registrations->submit($this->tenantId(), $request->validated()), 201);
    }

    #[OA\Get(path: '/api/v1/crm/deal-registrations/{id}', operationId: 'getCrmDealRegistration', description: 'Return a deal registration.', summary: 'Show a deal registration', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Partners'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmDealRegistration')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function show(string $id): JsonResponse
    {
        return $this->present($this->find(DealRegistration::class, $id, 'view', ['partner:id,name']));
    }

    #[OA\Post(path: '/api/v1/crm/deal-registrations/{id}/approve', operationId: 'approveCrmDealRegistration', description: 'Approves a pending registration; refused while another approved registration protects the same customer. Owners, administrators, sales managers.', summary: 'Approve a deal registration', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Partners'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmDealRegistration')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function approve(string $id): JsonResponse
    {
        $registration = $this->find(DealRegistration::class, $id, 'approve');

        return $this->present($this->registrations->approve($registration, $this->userId()));
    }

    #[OA\Post(path: '/api/v1/crm/deal-registrations/{id}/reject', operationId: 'rejectCrmDealRegistration', description: 'Rejects a pending or approved registration with a reason.', summary: 'Reject a deal registration', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmDecisionReason')), tags: ['CRM Partners'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmDealRegistration')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function reject(DecisionReasonRequest $request, string $id): JsonResponse
    {
        $registration = $this->find(DealRegistration::class, $id, 'approve');

        return $this->present($this->registrations->reject($registration, $this->userId(), (string) $request->validated('reason')));
    }

    #[OA\Post(path: '/api/v1/crm/deal-registrations/{id}/won', operationId: 'wonCrmDealRegistration', description: 'An approved registration is won; a linked opportunity must be won as well. Won registrations count for the partner tier.', summary: 'Mark a deal registration as won', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Partners'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmDealRegistration')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function won(string $id): JsonResponse
    {
        return $this->present($this->registrations->markWon($this->find(DealRegistration::class, $id, 'update')));
    }

    #[OA\Post(path: '/api/v1/crm/deal-registrations/expire', operationId: 'expireCrmDealRegistrations', description: 'Approved registrations whose protection ended become expired; returns how many.', summary: 'Expire overdue deal registrations', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Partners'], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function expire(): JsonResponse
    {
        $this->authorize('create', DealRegistration::class);

        return response()->json(['data' => ['expired' => $this->registrations->expireOverdue($this->tenantId())]]);
    }
}
