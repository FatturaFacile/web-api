<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\GeneralLedger\Exceptions\FiscalPeriodException;
use Modules\GeneralLedger\Exceptions\PayrollPostingException;
use Modules\GeneralLedger\Http\Requests\UpdatePayrollLedgerSettingRequest;
use Modules\GeneralLedger\Models\PayrollLedgerPosting;
use Modules\GeneralLedger\Models\PayrollLedgerSetting;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Services\PayrollLedgerService;
use OpenApi\Attributes as OA;

final class PayrollLedgerController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PayrollLedgerService $ledger
    ) {}

    /**
     * Returns the payroll accounts of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/payroll-settings',
        operationId: 'getGeneralLedgerPayrollSettings',
        description: 'Returns the accounts a payroll run posts to. There are no defaults: a run needing a missing account is refused and waits.',
        summary: 'Get payroll ledger settings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Payroll'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function showSettings(): JsonResponse
    {
        $this->authorize('manageSettings', VatSettlement::class);

        return response()->json(['data' => $this->settings()]);
    }

    /**
     * Updates the payroll accounts of the active tenant.
     */
    #[OA\Put(
        path: '/api/v1/general-ledger/payroll-settings',
        operationId: 'updateGeneralLedgerPayrollSettings',
        description: 'Updates the payroll accounts. `wage_type_accounts` maps a wage type code to its own account, replacing the account of its role; it is required for deductions and employer lines that are neither tax nor social contributions, such as salary advances or the Italian TFR.',
        summary: 'Update payroll ledger settings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'salary_expense_account_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'employer_cost_account_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'expense_allowance_account_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'employee_payable_account_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'social_payable_account_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'tax_payable_account_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'non_cash_benefit_account_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'rounding_account_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'wage_type_accounts', type: 'object', nullable: true),
        ])),
        tags: ['General Ledger Payroll'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function updateSettings(UpdatePayrollLedgerSettingRequest $request): JsonResponse
    {
        $this->authorize('manageSettings', VatSettlement::class);

        $settings = $this->settings();
        $settings->fill($request->validated());
        $settings->save();

        return response()->json(['data' => $settings]);
    }

    /**
     * Lists the payroll runs and their state towards the ledger.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/payroll-postings',
        operationId: 'listGeneralLedgerPayrollPostings',
        description: 'Lists the closed payroll runs with their state towards the ledger and, when waiting, the reason.',
        summary: 'List payroll postings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Payroll'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string', enum: ['pending', 'posted', 'reversal_pending', 'reversed', 'withdrawn'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function postings(Request $request): JsonResponse
    {
        $this->authorize('manageSettings', VatSettlement::class);

        $postings = PayrollLedgerPosting::query()
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->latest('run_date')
            ->get();

        return response()->json(['data' => $postings]);
    }

    /**
     * Posts a payroll run that was waiting.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/payroll-postings/{runId}/post',
        operationId: 'postGeneralLedgerPayrollRun',
        description: 'Posts a closed payroll run that was waiting, or books the reversal of a reopened one. Repeating it on a posted run changes nothing.',
        summary: 'Post waiting payroll run',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Payroll'],
        parameters: [
            new OA\Parameter(name: 'runId', in: 'path', required: true, description: 'Identifier of the payroll run', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Mapping or period missing: the run keeps waiting', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function post(string $runId): JsonResponse
    {
        $this->authorize('manageSettings', VatSettlement::class);

        $posting = PayrollLedgerPosting::query()->where('payroll_run_id', $runId)->firstOrFail();

        try {
            return response()->json(['data' => $this->ledger->retry($posting)]);
        } catch (FiscalPeriodException|PayrollPostingException $e) {
            return $e->render();
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    private function settings(): PayrollLedgerSetting
    {
        $tenantId = $this->tenantContext->getTenantId() ?? abort(403, 'Missing or unresolvable tenant context');

        return PayrollLedgerSetting::query()->where('tenant_id', $tenantId)->first()
            ?? new PayrollLedgerSetting(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId]);
    }
}
