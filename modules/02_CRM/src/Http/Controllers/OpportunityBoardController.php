<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Contracts\OpportunityServiceInterface;
use Modules\CRM\DTOs\UpdateOpportunityStageDTO;
use Modules\CRM\Http\Requests\MoveOpportunityRequest;
use Modules\CRM\Http\Requests\UpdateOpportunityNotesRequest;
use Modules\CRM\Http\Resources\OpportunityResource;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\OpportunityStage;
use Modules\CRM\Models\OpportunityStageHistory;
use Modules\CRM\Services\SalesBoardService;
use OpenApi\Attributes as OA;

/**
 * Board interactions on a deal: drag-and-drop move, quick note and stage history.
 */
final class OpportunityBoardController extends Controller
{
    public function __construct(
        private readonly OpportunityServiceInterface $opportunities,
        private readonly SalesBoardService $boards,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Moves a deal to a column and position of the Kanban board.
     */
    #[OA\Post(
        path: '/api/v1/crm/opportunities/{id}/move',
        operationId: 'moveCrmOpportunity',
        description: 'Moves a deal to a stage column at a position. Moving to a won stage closes it as won; moving to a lost stage requires loss_reason_code (from the loss-reason catalog) and the winning competitor, otherwise the call answers 422 and nothing changes. Returns the updated deal and the reconciled totals of the source and target columns.',
        summary: 'Move opportunity on the board',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Opportunities'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the opportunity', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['stage_id'], properties: [
            new OA\Property(property: 'stage_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'position', type: 'integer', minimum: 0, nullable: true),
            new OA\Property(property: 'probability', type: 'number', minimum: 0, maximum: 100, nullable: true),
            new OA\Property(property: 'loss_reason_code', type: 'string', nullable: true),
            new OA\Property(property: 'loss_notes', type: 'string', nullable: true),
            new OA\Property(property: 'competitor', type: 'string', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Updated deal and column totals', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 422, description: 'Validation error (loss questionnaire, pipeline mismatch)', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function move(MoveOpportunityRequest $request, string $id): JsonResponse
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $opportunity = Opportunity::where('tenant_id', $tenantId)->findOrFail($id);
        $target = OpportunityStage::where('tenant_id', $tenantId)->findOrFail($request->input('stage_id'));
        $actorId = (string) ($request->user()?->id ?? 'system');
        $sourceStageId = (string) $opportunity->stage_id;

        $updated = $target->id === $opportunity->stage_id
            ? $this->opportunities->reposition($opportunity, (int) $request->input('position', 0), $actorId)
            : $this->opportunities->changeStage($opportunity, new UpdateOpportunityStageDTO(
                targetStageCode: $target->code,
                amount: $request->filled('amount') ? (float) $request->input('amount') : null,
                probability: $request->filled('probability') ? (float) $request->input('probability') : null,
                forecastCategory: $request->input('forecast_category'),
                winLossReason: null,
                competitor: $request->input('competitor'),
                actualCloseDate: $request->input('actual_close_date'),
                lossReasonCode: $request->input('loss_reason_code'),
                lossNotes: $request->input('loss_notes'),
                position: $request->filled('position') ? (int) $request->input('position') : null,
            ), $actorId);

        $totals = [(string) $updated->stage_id => $this->boards->stageTotals($tenantId, (string) $updated->stage_id)];
        if ($sourceStageId !== (string) $updated->stage_id) {
            $totals[$sourceStageId] = $this->boards->stageTotals($tenantId, $sourceStageId);
        }

        return response()->json([
            'opportunity' => (new OpportunityResource($updated->load(['account', 'stage', 'owner'])))->resolve(),
            'stage_totals' => $totals,
        ]);
    }

    /**
     * Replaces or appends the quick note of a deal.
     */
    #[OA\Patch(
        path: '/api/v1/crm/opportunities/{id}/notes',
        operationId: 'updateCrmOpportunityNotes',
        description: 'Quick note update of a deal, meant for the smartphone bottom sheet: replaces the note, or with append=true adds a timestamped line. Connected devices are notified in real time.',
        summary: 'Update opportunity notes',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Opportunities'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the opportunity', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['notes'], properties: [
            new OA\Property(property: 'notes', type: 'string', maxLength: 5000),
            new OA\Property(property: 'append', type: 'boolean'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Updated deal', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function notes(UpdateOpportunityNotesRequest $request, string $id): JsonResponse
    {
        $opportunity = Opportunity::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
        $updated = $this->opportunities->updateNotes(
            $opportunity,
            (string) $request->input('notes'),
            $request->boolean('append'),
            (string) ($request->user()?->id ?? 'system'),
        );

        return response()->json((new OpportunityResource($updated->load(['account', 'stage', 'owner'])))->resolve());
    }

    /**
     * The stage history (audit trail) of a deal.
     */
    #[OA\Get(
        path: '/api/v1/crm/opportunities/{id}/history',
        operationId: 'listCrmOpportunityStageHistory',
        description: 'Lists the stage transitions of a deal, newest first, with the amounts, probability and weighted value at that moment and the loss data of a lost closure.',
        summary: 'List opportunity stage history',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Opportunities'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the opportunity', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'History', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function history(Request $request, string $id): JsonResponse
    {
        $opportunity = Opportunity::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
        $rows = OpportunityStageHistory::where('tenant_id', $opportunity->tenant_id)
            ->where('opportunity_id', $opportunity->id)
            ->with(['fromStage', 'toStage'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $rows->map(fn (OpportunityStageHistory $row): array => [
            'id' => (string) $row->id,
            'from_stage' => $row->fromStage?->label(),
            'to_stage' => $row->toStage?->label(),
            'amount' => (float) $row->amount,
            'probability' => (float) $row->probability,
            'weighted_amount' => (float) $row->weighted_amount,
            'loss_reason_code' => $row->loss_reason_code,
            'winning_competitor' => $row->winning_competitor,
            'changed_by_user_id' => $row->changed_by_user_id,
            'created_at' => $row->created_at?->toIso8601String(),
        ])->values()]);
    }
}
