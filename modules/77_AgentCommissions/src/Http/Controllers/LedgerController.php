<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\AgentCommissions\Application\Commands\SyncCommissionsCommand;
use Modules\AgentCommissions\Application\Commands\SyncCommissionsHandler;
use Modules\AgentCommissions\Application\Queries\ListLedgerHandler;
use Modules\AgentCommissions\Application\Queries\ListLedgerQuery;
use Modules\AgentCommissions\Http\Controllers\Concerns\ResolvesAgentCommissionContext;
use Modules\AgentCommissions\Http\Requests\LedgerIndexRequest;
use Modules\AgentCommissions\Http\Requests\SyncCommissionsRequest;
use Modules\AgentCommissions\Http\Resources\CommissionEntryResource;
use Modules\AgentCommissions\Models\CommissionEntry;
use OpenApi\Attributes as OA;

/**
 * The commission ledger: accrued commission per invoice line, invoiced or collected basis, with the lock status of the
 * cash-basis commissions that wait for a bank-reconciled collection.
 */
final class LedgerController extends Controller
{
    use ApiResponse;
    use ResolvesAgentCommissionContext;

    public function __construct(
        private readonly ListLedgerHandler $list,
        private readonly SyncCommissionsHandler $sync,
    ) {}

    #[OA\Get(path: '/api/v1/agent-commissions/ledger', operationId: 'agentCommissionsListLedger', summary: 'List the commission ledger entries with totals', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], responses: [new OA\Response(response: 200, description: 'Ledger entries')])]
    public function index(LedgerIndexRequest $request): JsonResponse
    {
        $result = $this->list->handle(new ListLedgerQuery(
            $request->validated('agent_profile_id'),
            $request->validated('status'),
            $request->validated('accrual_basis'),
            $request->validated('from'),
            $request->validated('to'),
            $request->validated('search'),
            (int) ($request->validated('per_page') ?? 25),
        ));
        $page = $result['page'];

        return $this->successResponse(CommissionEntryResource::collection($page->items()), 'OK', 200, [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'totals' => $result['totals'],
        ]);
    }

    #[OA\Get(path: '/api/v1/agent-commissions/ledger/{id}', operationId: 'agentCommissionsShowLedgerEntry', summary: 'Show a ledger entry with its movements', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Ledger entry')])]
    public function show(string $id): JsonResponse
    {
        Gate::authorize('viewAny', CommissionEntry::class);

        return $this->successResponse(new CommissionEntryResource(CommissionEntry::query()->with(['agent.representative:id,name', 'invoice:id,invoice_number,date,contact_id', 'movements'])->findOrFail($id)));
    }

    #[OA\Post(path: '/api/v1/agent-commissions/sync', operationId: 'agentCommissionsSyncLedger', summary: 'Accrue invoices of a period and re-read the collections and reconciliations of the open commissions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], responses: [new OA\Response(response: 200, description: 'Ledger synchronised')])]
    public function sync(SyncCommissionsRequest $request): JsonResponse
    {
        $result = $this->sync->handle(new SyncCommissionsCommand($request->validated('from'), $request->validated('to')));

        return $this->successResponse($result, $this->message('synced'));
    }
}
