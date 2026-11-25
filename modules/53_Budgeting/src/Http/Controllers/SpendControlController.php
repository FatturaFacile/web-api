<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Budgeting\Domain\SpendRequest;
use Modules\Budgeting\Http\Controllers\Concerns\InteractsWithBudgeting;
use Modules\Budgeting\Http\Requests\SettingsRequest;
use Modules\Budgeting\Http\Requests\SpendCheckRequest;
use Modules\Budgeting\Http\Resources\CommitmentResource;
use Modules\Budgeting\Http\Resources\SettingsResource;
use Modules\Budgeting\Http\Resources\SpendCheckResource;
use Modules\Budgeting\Models\BudgetCommitment;
use Modules\Budgeting\Models\BudgetSetting;
use Modules\Budgeting\Models\BudgetSpendCheck;
use Modules\Budgeting\Models\BudgetSpendRule;
use Modules\Budgeting\Services\AvailabilityService;
use Modules\Budgeting\Services\BudgetSettingsService;
use Modules\Budgeting\Services\SpendControlService;
use OpenApi\Attributes as OA;

/**
 * The spend lock: available budget of a scope, a "what if" of a spend, the open commitments, the log of controls that
 * did not pass cleanly, and the thresholds of the company.
 */
final class SpendControlController extends Controller
{
    use InteractsWithBudgeting;

    public function __construct(private readonly AvailabilityService $availability, private readonly SpendControlService $control, private readonly BudgetSettingsService $settings) {}

    #[OA\Get(path: '/api/v1/budgeting/spend-control/availability', operationId: 'showBudgetAvailability', description: 'Available budget = budget - (actual costs + open commitments) over the fiscal year of a date, for an account, cost centre and project (each optional).', summary: 'Available budget', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], responses: [
        new OA\Response(response: 200, description: 'Availability', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function availability(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BudgetCommitment::class);
        $filters = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'account_id' => ['nullable', 'uuid'], 'cost_center_id' => ['nullable', 'uuid'], 'project_id' => ['nullable', 'uuid']]);
        $availability = $this->availability->forScope($this->tenantId(), isset($filters['date']) ? Carbon::parse($filters['date']) : now(), $filters['account_id'] ?? null, $filters['cost_center_id'] ?? null, $filters['project_id'] ?? null);

        return response()->json(['data' => $availability->toArray()]);
    }

    #[OA\Post(path: '/api/v1/budgeting/spend-control/check', operationId: 'checkBudgetSpend', description: 'Would this spend pass the spend lock? Returns the decision (ok, warning, overridden or blocked), the shortfall and the availability. Nothing is recorded.', summary: 'Check a spend against the budget', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
        new OA\Response(response: 200, description: 'The decision', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function check(SpendCheckRequest $request): JsonResponse
    {
        $this->authorize('viewAny', BudgetCommitment::class);
        $data = $request->validated();
        $decision = $this->control->evaluate(new SpendRequest(
            tenantId: $this->tenantId(),
            sourceType: $data['source_type'],
            sourceId: null,
            sourceNumber: null,
            stage: $data['stage'] ?? BudgetSpendRule::StageSubmit,
            amount: bcadd((string) $data['amount'], '0', 2),
            accountId: $data['account_id'] ?? null,
            costCenterId: $data['cost_center_id'] ?? null,
            projectId: $data['project_id'] ?? null,
            date: isset($data['date']) ? Carbon::parse($data['date']) : now(),
            userId: $this->userId(),
        ), false);

        return response()->json(['data' => $decision->toArray()]);
    }

    #[OA\Get(path: '/api/v1/budgeting/spend-control/commitments', operationId: 'listBudgetCommitments', description: 'Commitments of requisitions and purchase orders against the budget. Filters: status, source_type, fiscal_year, cost_center_id, project_id.', summary: 'List budget commitments', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], responses: [
        new OA\Response(response: 200, description: 'Commitments', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function commitments(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BudgetCommitment::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([BudgetCommitment::Open, BudgetCommitment::Closed, BudgetCommitment::Released])],
            'source_type' => ['nullable', Rule::in([BudgetCommitment::Requisition, BudgetCommitment::PurchaseOrder])],
            'fiscal_year' => ['nullable', 'integer'],
            'cost_center_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
        ]);

        return CommitmentResource::collection(
            BudgetCommitment::query()
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['source_type']), static fn ($query) => $query->where('source_type', $filters['source_type']))
                ->when(isset($filters['fiscal_year']), static fn ($query) => $query->where('fiscal_year', $filters['fiscal_year']))
                ->when(isset($filters['cost_center_id']), static fn ($query) => $query->where('cost_center_id', $filters['cost_center_id']))
                ->when(isset($filters['project_id']), static fn ($query) => $query->where('project_id', $filters['project_id']))
                ->orderByDesc('commitment_date')
                ->orderByDesc('id')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Get(path: '/api/v1/budgeting/spend-control/checks', operationId: 'listBudgetSpendChecks', description: 'The spend controls that ended in a warning, a block or an override. Filters: outcome, source_type, source_id, cost_center_id.', summary: 'List spend controls', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], responses: [
        new OA\Response(response: 200, description: 'Controls', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function checks(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BudgetSpendCheck::class);
        $filters = $request->validate([
            'outcome' => ['nullable', Rule::in([BudgetSpendCheck::Warning, BudgetSpendCheck::Blocked, BudgetSpendCheck::Overridden])],
            'source_type' => ['nullable', Rule::in([BudgetCommitment::Requisition, BudgetCommitment::PurchaseOrder])],
            'source_id' => ['nullable', 'uuid'],
            'cost_center_id' => ['nullable', 'uuid'],
        ]);

        return SpendCheckResource::collection(
            BudgetSpendCheck::query()
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['outcome']), static fn ($query) => $query->where('outcome', $filters['outcome']))
                ->when(isset($filters['source_type']), static fn ($query) => $query->where('source_type', $filters['source_type']))
                ->when(isset($filters['source_id']), static fn ($query) => $query->where('source_id', $filters['source_id']))
                ->when(isset($filters['cost_center_id']), static fn ($query) => $query->where('cost_center_id', $filters['cost_center_id']))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Get(path: '/api/v1/budgeting/spend-control/settings', operationId: 'showBudgetSettings', description: 'The traffic light thresholds and the default validity of an approved override.', summary: 'Budget control settings', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], responses: [
        new OA\Response(response: 200, description: 'Settings', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
    ])]
    public function showSettings(): SettingsResource
    {
        $this->authorize('viewAny', BudgetSetting::class);

        return new SettingsResource($this->settings->get($this->tenantId()));
    }

    #[OA\Put(path: '/api/v1/budgeting/spend-control/settings', operationId: 'updateBudgetSettings', description: 'Change the traffic light thresholds (percent of the budget) and the default validity of an approved override. Reserved to owners, administrators, accountants and members granted budgeting.control.manage.', summary: 'Update budget control settings', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
        new OA\Response(response: 200, description: 'Settings updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function updateSettings(SettingsRequest $request): SettingsResource
    {
        $this->authorize('manageControl', BudgetSetting::forTenant($this->tenantId()));

        return new SettingsResource($this->settings->update($this->tenantId(), $request->validated()));
    }
}
