<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Http\Requests\CountDecisionRequest;
use Modules\Inventory\Http\Requests\RecordCountsBatchRequest;
use Modules\Inventory\Http\Resources\InventoryCountApprovalResource;
use Modules\Inventory\Http\Resources\InventoryCountLineResource;
use Modules\Inventory\Http\Resources\InventoryCountSessionResource;
use Modules\Inventory\Models\InventoryCountEvent;
use Modules\Inventory\Models\InventoryCountPolicy;
use Modules\Inventory\Models\InventoryCountSession;
use Modules\Inventory\Services\CountVarianceReportService;
use Modules\Inventory\Services\InventoryCountService;
use OpenApi\Attributes as OA;

/**
 * The floor and approval side of a physical count: the blind count sheet and the sessions assigned to a counter, batch
 * counts from offline devices, the reconciliation view with its variances, freeze control, counter assignment and the
 * two approval levels of the differences.
 */
#[OA\Tag(name: 'Inventory Count Workflow', description: 'Blind count sheets, batch counts, variance reconciliation, freeze control and multi-level approval')]
final class CountWorkflowController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/counts/assigned',
        operationId: 'invListAssignedCounts',
        summary: 'Count sessions open for counting',
        description: 'Sessions in progress, with assigned_to=me only those assigned to the caller. No quantities are returned: this is the task list of a counter.',
        tags: ['Inventory Count Workflow'],
        responses: [
            new OA\Response(response: 200, description: 'The sessions'),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        ]
    )]
    public function assigned(Request $request): JsonResponse
    {
        $sessions = InventoryCountSession::query()
            ->with(['warehouse', 'zone'])
            ->where('status', 'in_progress')
            ->when($request->query('assigned_to') === 'me', static fn ($query) => $query->where('assigned_to', (string) auth()->id()))
            ->orderBy('scheduled_date')
            ->limit(200)
            ->get();

        return response()->json(['data' => InventoryCountSessionResource::collection($sessions)->resolve($request)]);
    }

    #[OA\Get(
        path: '/api/v1/inventory/counts/{id}/sheet',
        operationId: 'invCountSheet',
        summary: 'The count sheet of a session',
        description: 'The lines to count. In a blind session (the default) the sheet never carries the system quantity, unit cost or variance, and the first count of a recounted line only when the policy reveals it. With pending=1 only the lines still to count (including recounts).',
        tags: ['Inventory Count Workflow'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'The lines'),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        ]
    )]
    public function sheet(string $id, Request $request): JsonResponse
    {
        $session = InventoryCountSession::query()->findOrFail($id);
        $this->authorize('view', $session);

        $revealFirst = ! $session->is_blind || InventoryCountPolicy::forTenant((string) $session->tenant_id)->reveal_first_count_on_recount;
        $lines = $session->lines()
            ->with(['item', 'bin'])
            ->when($request->boolean('pending'), static fn ($query) => $query->whereNull('counted_quantity'))
            ->orderBy('warehouse_bin_id')
            ->orderBy('id')
            ->get();

        return response()->json([
            'session' => (new InventoryCountSessionResource($session))->resolve($request),
            'data' => $lines->map(static fn ($line): array => (new InventoryCountLineResource($line, ! $session->is_blind, $revealFirst))->resolve($request))->all(),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/inventory/counts/{id}/record-counts',
        operationId: 'invRecordCountsBatch',
        summary: 'Record several counts at once (offline sync)',
        description: 'Each entry has a client entry id: a resend changes nothing. The answer lists every entry with its outcome (recorded, recount_required, rejected) and never carries system quantities.',
        tags: ['Inventory Count Workflow'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Outcome per entry'),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function recordBatch(string $id, RecordCountsBatchRequest $request, InventoryCountService $service): JsonResponse
    {
        $session = InventoryCountSession::query()->findOrFail($id);
        $this->authorize('recordCount', $session);

        $results = [];

        foreach ((array) $request->input('entries') as $entry) {
            try {
                $line = $service->recordCount(
                    (string) $entry['session_line_id'],
                    (string) $entry['counted_quantity'],
                    (string) auth()->id(),
                    (string) $session->id,
                    (string) $entry['client_entry_id'],
                    $entry['notes'] ?? null,
                    $request->input('device_id')
                );
                $results[] = ['client_entry_id' => $entry['client_entry_id'], 'session_line_id' => $entry['session_line_id'], 'status' => $line->recount_required ? 'recount_required' : 'recorded', 'recount_round' => (int) $line->recount_count];
            } catch (StockRuleViolation $violation) {
                $results[] = ['client_entry_id' => $entry['client_entry_id'], 'session_line_id' => $entry['session_line_id'], 'status' => 'rejected', 'message' => $violation->getMessage()];
            }
        }

        return response()->json(['data' => $results]);
    }

    #[OA\Get(
        path: '/api/v1/inventory/counts/{id}/variance',
        operationId: 'invCountVariance',
        summary: 'Reconciliation view of a count',
        description: 'Manager or finance approver only. System vs counted vs final quantity, variance quantity/value/percentage per line, totals, the approval level the variances call for, the approval trail and the links to the adjustment and its journal entry.',
        tags: ['Inventory Count Workflow'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'The reconciliation'),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        ]
    )]
    public function variance(string $id, Request $request, CountVarianceReportService $reports): JsonResponse
    {
        $session = InventoryCountSession::query()->with('warehouse')->findOrFail($id);
        $this->authorize('viewVariance', $session);

        $report = $reports->report($session);

        return response()->json([
            'session' => (new InventoryCountSessionResource($session))->resolve($request),
            'summary' => $report['summary'],
            'lines' => array_map(static fn ($line): array => (new InventoryCountLineResource($line, true, true))->resolve($request), $report['lines']),
            'approvals' => array_map(static fn ($approval): array => (new InventoryCountApprovalResource($approval))->resolve($request), $report['approvals']),
            'adjustment' => $report['adjustment'],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/inventory/counts/{id}/freeze',
        operationId: 'invFreezeCount',
        summary: 'Freeze the stock of an open count',
        description: 'Manager decision (owner/admin). From now on no movement may touch the counted area until the differences are approved or the count is cancelled.',
        tags: ['Inventory Count Workflow'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'The session'),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function freeze(string $id, CountDecisionRequest $request, InventoryCountService $service): JsonResponse
    {
        $session = InventoryCountSession::query()->findOrFail($id);
        $this->authorize('administer', $session);

        return response()->json(new InventoryCountSessionResource($service->freeze($session, (string) auth()->id())));
    }

    #[OA\Post(
        path: '/api/v1/inventory/counts/{id}/unfreeze',
        operationId: 'invUnfreezeCount',
        summary: 'Release the stock freeze of an open count',
        description: 'Manager decision (owner/admin), an administrative override: the reason is mandatory and kept in the audit log of the count.',
        tags: ['Inventory Count Workflow'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'The session'),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function unfreeze(string $id, CountDecisionRequest $request, InventoryCountService $service): JsonResponse
    {
        $session = InventoryCountSession::query()->findOrFail($id);
        $this->authorize('administer', $session);

        return response()->json(new InventoryCountSessionResource($service->unfreeze($session, (string) auth()->id(), (string) $request->input('reason'))));
    }

    #[OA\Post(
        path: '/api/v1/inventory/counts/{id}/assign',
        operationId: 'invAssignCount',
        summary: 'Assign a count to a counter',
        description: 'Manager decision (owner/admin). assigned_to null clears the assignment.',
        tags: ['Inventory Count Workflow'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'The session'),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function assign(string $id, CountDecisionRequest $request, InventoryCountService $service): JsonResponse
    {
        $session = InventoryCountSession::query()->findOrFail($id);
        $this->authorize('administer', $session);

        return response()->json(new InventoryCountSessionResource($service->assign($session, $request->input('assigned_to'), (string) auth()->id())));
    }

    #[OA\Post(
        path: '/api/v1/inventory/counts/{id}/approvals/{level}/approve',
        operationId: 'invApproveCountLevel',
        summary: 'Approve one level of the count differences',
        description: 'Level 1 is the warehouse manager (owner/admin), level 2 the finance approver (owner/accountant) and waits for level 1. Whoever submitted the differences cannot approve and each level needs a different person (segregation of duties, policy). The last approval posts stock movements and the General Ledger journal entry.',
        tags: ['Inventory Count Workflow'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/WmsRecordId'),
            new OA\Parameter(name: 'level', in: 'path', required: true, schema: new OA\Schema(type: 'integer', enum: [1, 2])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The session'),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function approve(string $id, int $level, CountDecisionRequest $request, InventoryCountService $service): JsonResponse
    {
        $session = InventoryCountSession::query()->findOrFail($id);
        $this->authorize('approveLevel', [$session, $level]);

        return response()->json(new InventoryCountSessionResource($service->approveLevel($session, $level, (string) auth()->id(), $request->input('notes'))));
    }

    #[OA\Post(
        path: '/api/v1/inventory/counts/{id}/approvals/{level}/reject',
        operationId: 'invRejectCountLevel',
        summary: 'Reject one level of the count differences',
        description: 'Same approvers as the approval. The reason is mandatory. Nothing is posted and the count goes back in progress (still frozen when it froze stock) to be recounted and submitted again.',
        tags: ['Inventory Count Workflow'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/WmsRecordId'),
            new OA\Parameter(name: 'level', in: 'path', required: true, schema: new OA\Schema(type: 'integer', enum: [1, 2])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The session'),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function reject(string $id, int $level, CountDecisionRequest $request, InventoryCountService $service): JsonResponse
    {
        $session = InventoryCountSession::query()->findOrFail($id);
        $this->authorize('approveLevel', [$session, $level]);

        return response()->json(new InventoryCountSessionResource($service->rejectLevel($session, $level, (string) auth()->id(), (string) $request->input('reason'))));
    }

    #[OA\Get(
        path: '/api/v1/inventory/counts/{id}/events',
        operationId: 'invCountEvents',
        summary: 'Audit log of a count',
        description: 'Manager or finance approver only: freeze and unfreeze, assignment, recounts, submission, approvals and rejections with user and time.',
        tags: ['Inventory Count Workflow'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'The events'),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        ]
    )]
    public function events(string $id): JsonResponse
    {
        $session = InventoryCountSession::query()->findOrFail($id);
        $this->authorize('viewVariance', $session);

        return response()->json(['data' => InventoryCountEvent::query()
            ->where('inventory_count_session_id', $session->id)
            ->orderBy('created_at')
            ->get(['id', 'inventory_count_line_id', 'event', 'user_id', 'payload', 'created_at'])]);
    }
}
