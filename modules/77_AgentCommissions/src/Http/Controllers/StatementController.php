<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\AgentCommissions\Application\Commands\ApproveStatementCommand;
use Modules\AgentCommissions\Application\Commands\ApproveStatementHandler;
use Modules\AgentCommissions\Application\Commands\GenerateStatementCommand;
use Modules\AgentCommissions\Application\Commands\GenerateStatementHandler;
use Modules\AgentCommissions\Application\Commands\MarkStatementPaidCommand;
use Modules\AgentCommissions\Application\Commands\MarkStatementPaidHandler;
use Modules\AgentCommissions\Application\Commands\PrepareSelfBillingCommand;
use Modules\AgentCommissions\Application\Commands\PrepareSelfBillingHandler;
use Modules\AgentCommissions\Application\Commands\SendStatementCommand;
use Modules\AgentCommissions\Application\Commands\SendStatementHandler;
use Modules\AgentCommissions\Application\Commands\VoidStatementCommand;
use Modules\AgentCommissions\Application\Commands\VoidStatementHandler;
use Modules\AgentCommissions\Application\Queries\GetStatementHandler;
use Modules\AgentCommissions\Application\Queries\GetStatementQuery;
use Modules\AgentCommissions\Application\Queries\ListStatementsHandler;
use Modules\AgentCommissions\Application\Queries\ListStatementsQuery;
use Modules\AgentCommissions\Application\Queries\PreviewStatementHandler;
use Modules\AgentCommissions\Application\Queries\PreviewStatementQuery;
use Modules\AgentCommissions\Http\Controllers\Concerns\ResolvesAgentCommissionContext;
use Modules\AgentCommissions\Http\Requests\GenerateStatementRequest;
use Modules\AgentCommissions\Http\Requests\PayStatementRequest;
use Modules\AgentCommissions\Http\Requests\SelfBillingRequest;
use Modules\AgentCommissions\Http\Requests\SendStatementRequest;
use Modules\AgentCommissions\Http\Requests\StatementActionRequest;
use Modules\AgentCommissions\Http\Requests\StatementIndexRequest;
use Modules\AgentCommissions\Http\Resources\StatementResource;
use Modules\AgentCommissions\Models\Statement;
use Modules\AgentCommissions\Services\StatementRenderer;
use OpenApi\Attributes as OA;

/**
 * Commission statements (estratto conto provvigionale) and their liquidation: generate, approve, prepare the commission
 * invoice / self-billing data, pay, send the PDF to the agent, void.
 */
final class StatementController extends Controller
{
    use ApiResponse;
    use ResolvesAgentCommissionContext;

    public function __construct(
        private readonly ListStatementsHandler $list,
        private readonly GetStatementHandler $get,
        private readonly PreviewStatementHandler $preview,
        private readonly GenerateStatementHandler $generate,
        private readonly ApproveStatementHandler $approve,
        private readonly VoidStatementHandler $void,
        private readonly PrepareSelfBillingHandler $selfBilling,
        private readonly MarkStatementPaidHandler $pay,
        private readonly SendStatementHandler $send,
        private readonly StatementRenderer $renderer,
    ) {}

    #[OA\Get(path: '/api/v1/agent-commissions/statements', operationId: 'agentCommissionsListStatements', summary: 'List commission statements', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], responses: [new OA\Response(response: 200, description: 'Statements')])]
    public function index(StatementIndexRequest $request): JsonResponse
    {
        $page = $this->list->handle(new ListStatementsQuery(
            $request->validated('agent_profile_id'),
            $request->validated('status'),
            isset($request->validated()['year']) ? (int) $request->validated('year') : null,
            (int) ($request->validated('per_page') ?? 25),
        ));

        return $this->successResponse(StatementResource::collection($page->items()), 'OK', 200, [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ]);
    }

    #[OA\Post(path: '/api/v1/agent-commissions/statements/preview', operationId: 'agentCommissionsPreviewStatement', summary: 'Preview the statement of an agent for a period without saving it', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], responses: [new OA\Response(response: 200, description: 'Statement preview')])]
    public function preview(GenerateStatementRequest $request): JsonResponse
    {
        return $this->successResponse($this->preview->handle(new PreviewStatementQuery(
            $this->tenantId(),
            (string) $request->validated('agent_profile_id'),
            (string) $request->validated('period_start'),
            (string) $request->validated('period_end'),
        )));
    }

    #[OA\Post(path: '/api/v1/agent-commissions/statements', operationId: 'agentCommissionsGenerateStatement', summary: 'Generate the commission statement of an agent for a period', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], responses: [new OA\Response(response: 201, description: 'Statement generated')])]
    public function store(GenerateStatementRequest $request): JsonResponse
    {
        $statement = $this->generate->handle(new GenerateStatementCommand(
            $this->tenantId(),
            (string) $request->validated('agent_profile_id'),
            (string) $request->validated('period_start'),
            (string) $request->validated('period_end'),
            (string) ($request->validated('period_type') ?? 'custom'),
            $this->userId($request),
        ));

        return $this->successResponse(new StatementResource($statement), $this->message('statement_generated'), 201);
    }

    #[OA\Get(path: '/api/v1/agent-commissions/statements/{id}', operationId: 'agentCommissionsShowStatement', summary: 'Show a commission statement with its lines', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Statement')])]
    public function show(string $id): JsonResponse
    {
        Gate::authorize('viewAny', Statement::class);

        return $this->successResponse(new StatementResource($this->get->handle(new GetStatementQuery($id))));
    }

    #[OA\Post(path: '/api/v1/agent-commissions/statements/{id}/approve', operationId: 'agentCommissionsApproveStatement', summary: 'Approve a draft statement', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Statement approved')])]
    public function approve(StatementActionRequest $request, string $id): JsonResponse
    {
        $statement = $this->approve->handle(new ApproveStatementCommand($id, $this->userId($request)));

        return $this->successResponse(new StatementResource($statement->load('lines')), $this->message('statement_approved'));
    }

    #[OA\Post(path: '/api/v1/agent-commissions/statements/{id}/void', operationId: 'agentCommissionsVoidStatement', summary: 'Void a draft or approved statement and give its lines back to the ledger', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Statement voided')])]
    public function void(StatementActionRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new StatementResource($this->void->handle(new VoidStatementCommand($id))), $this->message('statement_voided'));
    }

    #[OA\Post(path: '/api/v1/agent-commissions/statements/{id}/self-billing', operationId: 'agentCommissionsPrepareSelfBilling', summary: 'Prepare the commission invoice / self-billing data of an approved statement', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Self-billing data prepared')])]
    public function selfBilling(SelfBillingRequest $request, string $id): JsonResponse
    {
        $statement = $this->selfBilling->handle(new PrepareSelfBillingCommand($id, $request->validated('date')));

        return $this->successResponse(new StatementResource($statement), $this->message('statement_invoiced'));
    }

    #[OA\Post(path: '/api/v1/agent-commissions/statements/{id}/pay', operationId: 'agentCommissionsPayStatement', summary: 'Settle a statement (payout is refused while a cash-basis collection is not reconciled)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Statement settled')])]
    public function pay(PayStatementRequest $request, string $id): JsonResponse
    {
        $statement = $this->pay->handle(new MarkStatementPaidCommand($id, $request->validated('reference'), $request->validated('paid_on')));

        return $this->successResponse(new StatementResource($statement), $this->message('statement_paid'));
    }

    #[OA\Post(path: '/api/v1/agent-commissions/statements/{id}/send', operationId: 'agentCommissionsSendStatement', summary: 'Email the statement PDF to the agent', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Statement sent')])]
    public function send(SendStatementRequest $request, string $id): JsonResponse
    {
        $statement = $this->send->handle(new SendStatementCommand($id, $this->locale($request), $request->validated('recipient')));

        return $this->successResponse(new StatementResource($statement), $this->message('statement_sent'));
    }

    #[OA\Get(path: '/api/v1/agent-commissions/statements/{id}/pdf', operationId: 'agentCommissionsStatementPdf', summary: 'Download the statement as a PDF', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'PDF document')])]
    public function pdf(string $id): Response
    {
        Gate::authorize('viewAny', Statement::class);
        $statement = $this->get->handle(new GetStatementQuery($id));
        $locale = in_array(app()->getLocale(), ['it', 'en', 'de', 'fr'], true) ? app()->getLocale() : 'it';

        return response($this->renderer->pdf($statement, $locale), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="statement-'.$statement->number.'.pdf"',
        ]);
    }
}
