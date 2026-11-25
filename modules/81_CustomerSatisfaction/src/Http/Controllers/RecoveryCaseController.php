<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\CustomerSatisfaction\Domain\Enums\RecoveryStatus;
use Modules\CustomerSatisfaction\Http\Controllers\Concerns\InteractsWithSatisfaction;
use Modules\CustomerSatisfaction\Http\Requests\AssignCaseRequest;
use Modules\CustomerSatisfaction\Http\Requests\ListFiltersRequest;
use Modules\CustomerSatisfaction\Http\Requests\TransitionCaseRequest;
use Modules\CustomerSatisfaction\Http\Resources\RecoveryCaseResource;
use Modules\CustomerSatisfaction\Models\DetractorRecoveryCase;
use Modules\CustomerSatisfaction\Services\DetractorRecoveryService;
use OpenApi\Attributes as OA;

/**
 * The closed-loop detractor recovery queue: cases with their 24 hour SLA, owners, churn risk and resolution.
 */
final class RecoveryCaseController extends Controller
{
    use ApiResponse;
    use InteractsWithSatisfaction;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    private const array WITH = ['customer', 'accountManager', 'supportLead', 'response.template'];

    #[OA\Get(path: '/api/v1/customer-satisfaction/recovery-cases', operationId: 'customerSatisfactionListRecoveryCases', summary: 'List the detractor recovery cases, the most urgent SLA first', security: self::SECURITY, tags: ['Customer Satisfaction'], responses: [new OA\Response(response: 200, description: 'Cases, paginated')])]
    public function index(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', DetractorRecoveryCase::class);

        $page = DetractorRecoveryCase::query()
            ->with(self::WITH)
            ->when($request->input('status'), fn ($rows, $status) => $rows->where('status', $status))
            ->when($request->boolean('open_only'), fn ($rows) => $rows->whereNotIn('status', [RecoveryStatus::Resolved->value, RecoveryStatus::Closed->value]))
            ->when($request->input('priority'), fn ($rows, $priority) => $rows->where('priority', $priority))
            ->when($request->input('customer_id'), fn ($rows, $customer) => $rows->where('customer_id', $customer))
            ->orderBy('sla_due_at')
            ->paginate($this->perPage());

        return $this->paged($page, RecoveryCaseResource::class);
    }

    #[OA\Get(path: '/api/v1/customer-satisfaction/recovery-cases/{id}', operationId: 'customerSatisfactionShowRecoveryCase', summary: 'Show a detractor recovery case', security: self::SECURITY, tags: ['Customer Satisfaction'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Case')])]
    public function show(string $id): JsonResponse
    {
        $case = DetractorRecoveryCase::query()->with(self::WITH)->findOrFail($id);
        Gate::authorize('view', $case);

        return $this->successResponse(new RecoveryCaseResource($case), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/customer-satisfaction/recovery-cases/{id}/transition', operationId: 'customerSatisfactionTransitionRecoveryCase', summary: 'Move a case through the workflow (resolving needs the root cause and the resolution)', security: self::SECURITY, tags: ['Customer Satisfaction'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Case updated')])]
    public function transition(TransitionCaseRequest $request, DetractorRecoveryService $recovery, string $id): JsonResponse
    {
        $case = DetractorRecoveryCase::query()->findOrFail($id);
        Gate::authorize('update', $case);
        $data = $request->validated();

        $recovery->transition($case, RecoveryStatus::from($data['status']), $data['root_cause_code'] ?? null, $data['resolution_summary'] ?? null);

        return $this->successResponse(new RecoveryCaseResource($case->refresh()->load(self::WITH)), $this->message('saved'));
    }

    #[OA\Put(path: '/api/v1/customer-satisfaction/recovery-cases/{id}/assign', operationId: 'customerSatisfactionAssignRecoveryCase', summary: 'Assign the account manager and the support lead of a case', security: self::SECURITY, tags: ['Customer Satisfaction'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Case updated')])]
    public function assign(AssignCaseRequest $request, DetractorRecoveryService $recovery, string $id): JsonResponse
    {
        $case = DetractorRecoveryCase::query()->findOrFail($id);
        Gate::authorize('update', $case);

        $recovery->assign($case, $request->validated('assigned_account_manager_id'), $request->validated('assigned_support_lead_id'));

        return $this->successResponse(new RecoveryCaseResource($case->refresh()->load(self::WITH)), $this->message('saved'));
    }
}
