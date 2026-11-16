<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\CRM\Http\Requests\DecisionReasonRequest;
use Modules\CRM\Models\MdfClaim;
use Modules\CRM\Services\MdfService;
use OpenApi\Attributes as OA;

/**
 * MDF claims: review and payment.
 */
final class MdfClaimController extends CrmExtensionController
{
    public function __construct(private readonly MdfService $mdf) {}

    #[OA\Get(path: '/api/v1/crm/mdf-claims', operationId: 'listCrmMdfClaims', description: 'All MDF claims, latest first. Filter: status.', summary: 'List MDF claims', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Partners'], responses: [
        new OA\Response(response: 200, description: 'Records', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CrmMdfClaim'))])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->validate(['status' => ['nullable', 'string']])['status'] ?? null;

        return $this->paginated($this->scoped(MdfClaim::class)->when($status !== null, static fn ($query) => $query->where('status', $status))->orderByDesc('created_at'));
    }

    #[OA\Post(path: '/api/v1/crm/mdf-claims/{id}/approve', operationId: 'approveCrmMdfClaim', description: 'Approves a submitted claim if the fund still covers it. Owners, administrators, sales managers.', summary: 'Approve an MDF claim', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Partners'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmMdfClaim')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function approve(string $id): JsonResponse
    {
        return $this->present($this->mdf->approve($this->find(MdfClaim::class, $id, 'approve', ['fund']), $this->userId()));
    }

    #[OA\Post(path: '/api/v1/crm/mdf-claims/{id}/reject', operationId: 'rejectCrmMdfClaim', description: 'Rejects a submitted claim with a reason.', summary: 'Reject an MDF claim', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmDecisionReason')), tags: ['CRM Partners'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmMdfClaim')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function reject(DecisionReasonRequest $request, string $id): JsonResponse
    {
        return $this->present($this->mdf->reject($this->find(MdfClaim::class, $id, 'approve'), $this->userId(), (string) $request->validated('reason')));
    }

    #[OA\Post(path: '/api/v1/crm/mdf-claims/{id}/pay', operationId: 'payCrmMdfClaim', description: 'An approved claim is paid.', summary: 'Mark an MDF claim as paid', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Partners'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmMdfClaim')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function pay(string $id): JsonResponse
    {
        return $this->present($this->mdf->markPaid($this->find(MdfClaim::class, $id, 'approve')));
    }
}
