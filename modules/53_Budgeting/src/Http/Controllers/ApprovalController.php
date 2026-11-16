<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Budgeting\Http\Controllers\Concerns\InteractsWithBudgeting;
use Modules\Budgeting\Http\Requests\ApprovalDecisionRequest;
use Modules\Budgeting\Http\Resources\VersionResource;
use Modules\Budgeting\Models\BudgetVersion;
use Modules\Budgeting\Services\ApprovalService;
use OpenApi\Attributes as OA;

/**
 * Approval workflow of a version: submit, approve, reject, lock.
 */
final class ApprovalController extends Controller
{
    use InteractsWithBudgeting;

    public function __construct(private readonly ApprovalService $approvals) {}

    #[OA\Post(path: '/api/v1/budgeting/versions/{id}/submit', operationId: 'submitBudgetVersion', description: 'Send a draft version to approval. The version needs at least one line; from now on it is read-only.', summary: 'Submit a version for approval', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/BudgetApprovalRequest')), tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 200, description: 'Version submitted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BudgetVersion')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function submit(ApprovalDecisionRequest $request, string $id): VersionResource
    {
        $version = BudgetVersion::query()->findOrFail($id);
        $this->authorize('update', $version);

        return $this->respond($this->approvals->submit($version, $this->userId(), $request->validated()['comment'] ?? null));
    }

    #[OA\Post(path: '/api/v1/budgeting/versions/{id}/approve', operationId: 'approveBudgetVersion', description: 'Approve a submitted version. A company has one approved or locked budget per fiscal year. Reserved to owners, administrators and members granted budgeting.approve.', summary: 'Approve a version', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/BudgetApprovalRequest')), tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 200, description: 'Version approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BudgetVersion')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function approve(ApprovalDecisionRequest $request, string $id): VersionResource
    {
        $version = BudgetVersion::query()->findOrFail($id);
        $this->authorize('approve', $version);

        return $this->respond($this->approvals->approve($version, $this->userId(), $request->validated()['comment'] ?? null));
    }

    #[OA\Post(path: '/api/v1/budgeting/versions/{id}/reject', operationId: 'rejectBudgetVersion', description: 'Send a submitted or approved (not yet locked) version back to draft. The comment with the reason is required. Reserved to approvers.', summary: 'Reject a version', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BudgetApprovalRequest')), tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 200, description: 'Version back to draft', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BudgetVersion')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function reject(ApprovalDecisionRequest $request, string $id): VersionResource
    {
        $version = BudgetVersion::query()->findOrFail($id);
        $this->authorize('approve', $version);

        return $this->respond($this->approvals->reject($version, $this->userId(), $request->validated()['comment'] ?? null));
    }

    #[OA\Post(path: '/api/v1/budgeting/versions/{id}/lock', operationId: 'lockBudgetVersion', description: 'Lock an approved version: it becomes immutable. Reserved to approvers.', summary: 'Lock a version', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/BudgetApprovalRequest')), tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 200, description: 'Version locked', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BudgetVersion')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function lock(ApprovalDecisionRequest $request, string $id): VersionResource
    {
        $version = BudgetVersion::query()->findOrFail($id);
        $this->authorize('approve', $version);

        return $this->respond($this->approvals->lock($version, $this->userId(), $request->validated()['comment'] ?? null));
    }

    private function respond(BudgetVersion $version): VersionResource
    {
        return new VersionResource($version->loadCount(['lines', 'drivers'])->loadSum('lines', 'amount')->load('approvals'));
    }
}
