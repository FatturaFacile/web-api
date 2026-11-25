<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Modules\Treasury\Http\Requests\RunSmartReconciliationRequest;
use Modules\Treasury\Http\Requests\SmartReconciliationLinesRequest;
use Modules\Treasury\Http\Resources\ReconciliationProposalResource;
use Modules\Treasury\Models\ReconciliationProposal;
use Modules\Treasury\Services\Reconciliation\ReconciliationProposalService;
use OpenApi\Attributes as OA;

/**
 * Workbench of the smart reconciliation: statement lines with their ranked proposals, 1-click apply / reject and undo.
 */
final class SmartReconciliationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReconciliationProposalService $proposals) {}

    #[OA\Get(
        path: '/api/v1/treasury/smart-reconciliation/lines',
        operationId: 'listTreasurySmartReconciliationLines',
        description: 'Statement lines with their pending proposals (rule, confidence, fee and exchange difference), or the applied proposal of reconciled lines.',
        summary: 'List lines of the reconciliation workbench',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Smart Reconciliation'],
        responses: [new OA\Response(response: 200, description: 'Lines', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function lines(SmartReconciliationLinesRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $status = $filters['status'] ?? 'open';
        $search = isset($filters['search']) ? '%'.strtolower($filters['search']).'%' : null;
        $min = $filters['min_confidence'] ?? null;

        $page = BankStatementLine::query()
            ->when($status !== 'all', fn ($query) => $query->where('reconciled', $status === 'reconciled'))
            ->when(isset($filters['direction']), fn ($query) => $query->where('direction', $filters['direction']))
            ->when(isset($filters['statement_id']), fn ($query) => $query->where('bank_statement_id', $filters['statement_id']))
            ->when($min !== null, fn ($query) => $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('reconciliation_proposals')
                ->whereColumn('reconciliation_proposals.bank_statement_line_id', 'bank_statement_lines.id')
                ->where('reconciliation_proposals.status', ReconciliationProposal::PENDING)
                ->where('reconciliation_proposals.confidence', '>=', $min)))
            ->when($request->boolean('with_proposals'), fn ($query) => $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('reconciliation_proposals')
                ->whereColumn('reconciliation_proposals.bank_statement_line_id', 'bank_statement_lines.id')
                ->where('reconciliation_proposals.status', ReconciliationProposal::PENDING)))
            ->when($search !== null, fn ($query) => $query->where(fn ($inner) => $inner
                ->whereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$search])
                ->orWhereRaw('LOWER(COALESCE(counterpart_name, \'\')) LIKE ?', [$search])
                ->orWhereRaw('LOWER(COALESCE(reference, \'\')) LIKE ?', [$search])
                ->orWhereRaw('LOWER(COALESCE(qr_reference, \'\')) LIKE ?', [$search])))
            ->orderByDesc('booking_date')
            ->orderBy('line_number')
            ->paginate((int) ($filters['per_page'] ?? 25));

        $ids = $page->getCollection()->pluck('id')->all();
        $byLine = ReconciliationProposal::query()
            ->whereIn('bank_statement_line_id', $ids)
            ->whereIn('status', [ReconciliationProposal::PENDING, ReconciliationProposal::APPLIED])
            ->orderByDesc('confidence')
            ->get()
            ->groupBy('bank_statement_line_id');

        return $this->successResponse(
            data: $page->getCollection()->map(fn (BankStatementLine $line): array => $this->presentLine($line, $byLine->get($line->id, collect())))->values(),
            message: __('banking.messages.listed'),
            meta: ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        );
    }

    #[OA\Get(
        path: '/api/v1/treasury/smart-reconciliation/summary',
        operationId: 'summarizeTreasurySmartReconciliation',
        description: 'Counters of the workbench: open and reconciled lines, lines with a pending proposal, applied proposals.',
        summary: 'Smart reconciliation summary',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Smart Reconciliation'],
        responses: [new OA\Response(response: 200, description: 'Summary', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function summary(): JsonResponse
    {
        $pending = ReconciliationProposal::query()->where('status', ReconciliationProposal::PENDING);

        return $this->successResponse([
            'open_lines' => BankStatementLine::query()->where('reconciled', false)->count(),
            'reconciled_lines' => BankStatementLine::query()->where('reconciled', true)->count(),
            'lines_with_proposals' => (clone $pending)->distinct()->count('bank_statement_line_id'),
            'high_confidence' => (clone $pending)->where('confidence', '>=', 95)->distinct()->count('bank_statement_line_id'),
            'applied_proposals' => ReconciliationProposal::query()->where('status', ReconciliationProposal::APPLIED)->count(),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/treasury/smart-reconciliation/run',
        operationId: 'runTreasurySmartReconciliation',
        description: 'Computes the proposals of every open line of a statement; with auto_apply the unique proposals at or above the auto-apply confidence are booked.',
        summary: 'Run the smart reconciliation on a statement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        tags: ['Treasury Smart Reconciliation'],
        responses: [new OA\Response(response: 200, description: 'Counters', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function run(RunSmartReconciliationRequest $request): JsonResponse
    {
        $statement = BankStatement::query()->findOrFail($request->validated('statement_id'));

        return $this->successResponse($this->proposals->run($statement, $request->boolean('auto_apply')), __('treasury_flows.messages.reconciliation_run'));
    }

    #[OA\Post(
        path: '/api/v1/treasury/smart-reconciliation/lines/{id}/refresh',
        operationId: 'refreshTreasurySmartReconciliationLine',
        description: 'Recomputes the proposals of one open line.',
        summary: 'Refresh the proposals of a line',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Smart Reconciliation'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Proposals', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function refresh(string $id): JsonResponse
    {
        $line = BankStatementLine::query()->findOrFail($id);

        return $this->successResponse($this->presentLine($line, $this->proposals->refresh($line)));
    }

    #[OA\Post(
        path: '/api/v1/treasury/smart-reconciliation/proposals/{id}/apply',
        operationId: 'applyTreasurySmartReconciliationProposal',
        description: '1-click assisted match: settles the document, books bank fees and exchange differences and posts the prima nota atomically.',
        summary: 'Apply a proposal',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Smart Reconciliation'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Reconciled line', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Proposal or line no longer open, or posting refused', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function apply(string $id): JsonResponse
    {
        $proposal = ReconciliationProposal::query()->findOrFail($id);
        $line = $this->proposals->apply($proposal, (string) request()->user()?->id);

        return $this->successResponse($this->presentLine($line, ReconciliationProposal::query()->where('bank_statement_line_id', $line->id)->where('status', ReconciliationProposal::APPLIED)->get()), __('treasury_flows.messages.proposal_applied'));
    }

    #[OA\Post(
        path: '/api/v1/treasury/smart-reconciliation/proposals/{id}/reject',
        operationId: 'rejectTreasurySmartReconciliationProposal',
        description: 'Discards a proposal; it is not proposed again for the same line.',
        summary: 'Reject a proposal',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Smart Reconciliation'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Proposal', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function reject(string $id): JsonResponse
    {
        return $this->successResponse(new ReconciliationProposalResource($this->proposals->reject(ReconciliationProposal::query()->findOrFail($id))), __('treasury_flows.messages.proposal_rejected'));
    }

    #[OA\Post(
        path: '/api/v1/treasury/smart-reconciliation/lines/{id}/unmatch',
        operationId: 'unmatchTreasurySmartReconciliationLine',
        description: 'Takes back a reconciliation: payments and postings (fees, exchange differences) are reversed and the line is open again.',
        summary: 'Undo a reconciliation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Smart Reconciliation'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Open line', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function unmatch(string $id): JsonResponse
    {
        $line = $this->proposals->unmatch(BankStatementLine::query()->findOrFail($id));

        return $this->successResponse($this->presentLine($line, collect()), __('treasury_flows.messages.reconciliation_undone'));
    }

    /**
     * @param  Collection<int, ReconciliationProposal>  $proposals
     * @return array<string, mixed>
     */
    private function presentLine(BankStatementLine $line, Collection $proposals): array
    {
        return [
            'id' => $line->id,
            'bank_statement_id' => $line->bank_statement_id,
            'line_number' => (int) $line->line_number,
            'booking_date' => CarbonImmutable::parse($line->booking_date)->toDateString(),
            'value_date' => CarbonImmutable::parse($line->value_date)->toDateString(),
            'direction' => $line->direction,
            'amount' => (string) $line->amount,
            'fee_amount' => (string) $line->fee_amount,
            'currency' => $line->currency,
            'reference' => $line->reference,
            'qr_reference' => $line->qr_reference,
            'description' => $line->description,
            'counterpart_name' => $line->counterpart_name,
            'counterpart_iban' => $line->counterpart_iban,
            'reconciled' => (bool) $line->reconciled,
            'reconciliation_type' => $line->reconciliation_type,
            'matched_document_id' => $line->matched_document_id,
            'proposals' => ReconciliationProposalResource::collection($proposals->values()),
        ];
    }
}
