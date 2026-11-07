<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Invoicing\Http\Requests\CommissionPeriodRequest;
use Modules\Invoicing\Http\Requests\SaveCommissionSettingsRequest;
use Modules\Invoicing\Http\Requests\SaveSalesTargetRequest;
use Modules\Invoicing\Http\Requests\SaveSalesTeamRequest;
use Modules\Invoicing\Models\SalesCommissionSetting;
use Modules\Invoicing\Models\SalesCommissionStatement;
use Modules\Invoicing\Models\SalesRepresentative;
use Modules\Invoicing\Models\SalesTarget;
use Modules\Invoicing\Models\SalesTeam;
use Modules\Invoicing\Services\SalesCommissionService;
use OpenApi\Attributes as OA;

/**
 * Sales targets, attainment, commission statements and their accrual.
 */
final class SalesCommissionController extends Controller
{
    public function __construct(
        private readonly SalesCommissionService $commissions,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Create a team.
     */
    #[OA\Post(path: '/api/v1/invoicing/sales-teams', operationId: 'storeSalesTeam', description: 'Create a sales team and put agents in it.', summary: 'Create sales team', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSaveSalesTeamRequest')), tags: ['Invoicing'], responses: [
        new OA\Response(response: 201, description: 'Team', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function storeTeam(SaveSalesTeamRequest $request): JsonResponse
    {
        $this->authorizeManager();
        $team = SalesTeam::create(['name' => $request->validated('name')]);
        SalesRepresentative::query()->whereKey($request->validated('sales_representative_ids', []))->update(['sales_team_id' => $team->id]);

        return response()->json(['data' => $team], 201);
    }

    /**
     * Set a target.
     */
    #[OA\Post(path: '/api/v1/invoicing/sales-targets', operationId: 'storeSalesTarget', description: 'Set the net sales expected of an agent or a team in a period.', summary: 'Create sales target', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSaveSalesTargetRequest')), tags: ['Invoicing'], responses: [
        new OA\Response(response: 201, description: 'Target', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function storeTarget(SaveSalesTargetRequest $request): JsonResponse
    {
        $this->authorizeManager();

        return response()->json(['data' => SalesTarget::create($request->validated())], 201);
    }

    /**
     * Attainment of the targets.
     */
    #[OA\Get(path: '/api/v1/invoicing/sales-targets/attainment', operationId: 'salesTargetAttainment', description: 'Compare the targets touching a period with the net sales of issued invoices; credit notes count against them. Agents see the own targets, managers all of them.', summary: 'Sales target attainment', security: [['bearerAuth' => []]], tags: ['Invoicing'], parameters: [
        new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
    ], responses: [
        new OA\Response(response: 200, description: 'Attainment', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesTargetAttainment')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function attainment(CommissionPeriodRequest $request): JsonResponse
    {
        $rows = $this->commissions->attainment((string) $request->validated('from'), (string) $request->validated('to'));

        if (! $this->isManager()) {
            $own = $this->ownRepresentative($request);
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $own !== null && ($row['owner_id'] === $own->id || $row['owner_id'] === $own->sales_team_id)));
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * Accrue the commissions of a period.
     */
    #[OA\Post(path: '/api/v1/invoicing/commissions/accrue', operationId: 'accrueSalesCommissions', description: 'Create a commission for each issued invoice of the period carrying an agent, once. Invoices in another currency are counted and left out.', summary: 'Accrue commissions', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/InvoicingCommissionPeriodRequest')), tags: ['Invoicing'], responses: [
        new OA\Response(response: 200, description: 'Outcome', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function accrue(CommissionPeriodRequest $request): JsonResponse
    {
        $this->authorizeManager();

        return response()->json(['data' => $this->commissions->accrue((string) $this->tenantContext->getTenantId(), (string) $request->validated('from'), (string) $request->validated('to'))]);
    }

    /**
     * List statements.
     */
    #[OA\Get(path: '/api/v1/invoicing/commission-statements', operationId: 'listCommissionStatements', description: 'Return the commission statements: all for managers, the own ones for an agent.', summary: 'List commission statements', security: [['bearerAuth' => []]], tags: ['Invoicing'], responses: [
        new OA\Response(response: 200, description: 'Statements', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
    ])]
    public function statements(Request $request): JsonResponse
    {
        $own = $this->isManager() ? null : $this->ownRepresentative($request);

        $statements = SalesCommissionStatement::query()
            ->when(! $this->isManager(), static fn ($query) => $query->where('sales_representative_id', $own?->id))
            ->orderByDesc('period_start')
            ->get();

        return response()->json(['data' => $statements]);
    }

    /**
     * Create a statement.
     */
    #[OA\Post(path: '/api/v1/invoicing/commission-statements', operationId: 'storeCommissionStatement', description: 'Gather the accrued commissions of an agent for a period into a draft statement.', summary: 'Create commission statement', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/InvoicingCommissionPeriodRequest')), tags: ['Invoicing'], responses: [
        new OA\Response(response: 201, description: 'Statement', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingCommissionStatement')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function storeStatement(CommissionPeriodRequest $request): JsonResponse
    {
        $this->authorizeManager();
        $representative = SalesRepresentative::query()->findOrFail($request->validated('sales_representative_id'));

        return $this->attempt(fn (): SalesCommissionStatement => $this->commissions->createStatement((string) $this->tenantContext->getTenantId(), $representative, (string) $request->validated('from'), (string) $request->validated('to')), 201);
    }

    /**
     * Approve a statement.
     */
    #[OA\Post(path: '/api/v1/invoicing/commission-statements/{id}/approve', operationId: 'approveCommissionStatement', description: 'Approve a statement and accrue the payable in the ledger. A refused posting stays on the statement in ledger_error and ledger_status pending; the approval stands.', summary: 'Approve commission statement', security: [['bearerAuth' => []]], tags: ['Invoicing'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 200, description: 'Statement', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingCommissionStatement')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function approve(Request $request, string $id): JsonResponse
    {
        $this->authorizeManager();
        $statement = SalesCommissionStatement::query()->findOrFail($id);
        /** @var User $actor */
        $actor = $request->user();

        return $this->attempt(fn (): SalesCommissionStatement => $this->commissions->approve($statement, $actor));
    }

    /**
     * Post a waiting statement.
     */
    #[OA\Post(path: '/api/v1/invoicing/commission-statements/{id}/post', operationId: 'postCommissionStatement', description: 'Accrue a statement that waits for the ledger. A refusal answers 422 with the reason.', summary: 'Post commission statement', security: [['bearerAuth' => []]], tags: ['Invoicing'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 200, description: 'Statement', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingCommissionStatement')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function post(string $id): JsonResponse
    {
        $this->authorizeManager();
        $statement = SalesCommissionStatement::query()->findOrFail($id);

        return $this->attempt(fn (): SalesCommissionStatement => $this->commissions->retryPosting($statement));
    }

    /**
     * Mark a statement as paid.
     */
    #[OA\Post(path: '/api/v1/invoicing/commission-statements/{id}/paid', operationId: 'markCommissionStatementPaid', description: 'Record that an approved statement was paid. The payment itself and its entry belong to Treasury or to the payroll.', summary: 'Mark commission statement paid', security: [['bearerAuth' => []]], tags: ['Invoicing'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 200, description: 'Statement', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingCommissionStatement')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function markPaid(string $id): JsonResponse
    {
        $this->authorizeManager();
        $statement = SalesCommissionStatement::query()->findOrFail($id);

        return $this->attempt(fn (): SalesCommissionStatement => $this->commissions->markPaid($statement));
    }

    /**
     * Set the accrual accounts.
     */
    #[OA\Put(path: '/api/v1/invoicing/commission-settings', operationId: 'saveCommissionSettings', description: 'Set the accounts of the accrual. There are no defaults: without them a statement waits for the ledger.', summary: 'Save commission settings', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSaveCommissionSettingsRequest')), tags: ['Invoicing'], responses: [
        new OA\Response(response: 200, description: 'Settings', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function saveSettings(SaveCommissionSettingsRequest $request): JsonResponse
    {
        $this->authorizeManager();
        $settings = SalesCommissionSetting::query()->first() ?? new SalesCommissionSetting;
        $settings->fill($request->validated())->save();

        return response()->json(['data' => $settings]);
    }

    /**
     * @param  callable(): SalesCommissionStatement  $action
     */
    private function attempt(callable $action, int $status = 200): JsonResponse
    {
        try {
            return response()->json(['data' => $action()], $status);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function isManager(): bool
    {
        return Gate::allows('manage-sales-commissions');
    }

    private function authorizeManager(): void
    {
        Gate::authorize('manage-sales-commissions');
    }

    private function ownRepresentative(Request $request): ?SalesRepresentative
    {
        return SalesRepresentative::query()->where('user_id', $request->user()?->id)->first();
    }
}
