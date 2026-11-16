<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\BusinessIntelligence\Http\Controllers\Concerns\InteractsWithBi;
use Modules\BusinessIntelligence\Http\Resources\SnapshotResource;
use Modules\BusinessIntelligence\Models\BiDashboard;
use Modules\BusinessIntelligence\Models\BiSavedQuery;
use Modules\BusinessIntelligence\Models\BiSnapshot;
use Modules\BusinessIntelligence\Services\SnapshotService;
use OpenApi\Attributes as OA;

/**
 * Snapshots of saved queries and dashboards, taken by hand or on the schedule of the saved query.
 */
final class SnapshotController extends Controller
{
    use InteractsWithBi;

    public function __construct(private readonly SnapshotService $snapshots) {}

    #[OA\Get(path: '/api/v1/business-intelligence/snapshots', operationId: 'listBiSnapshots', description: 'Snapshots of a saved query or a dashboard the caller can open, newest first, without the stored figures. Filters: source_type, source_id.', summary: 'List snapshots', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], parameters: [
        new OA\Parameter(name: 'source_type', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['saved_query', 'dashboard'])),
        new OA\Parameter(name: 'source_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Snapshots', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/BiSnapshot'))])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BiUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['source_type' => ['required', Rule::in([BiSnapshot::SavedQuery, BiSnapshot::Dashboard])], 'source_id' => ['required', 'uuid']]);
        $this->authorize('view', $this->source($filters['source_type'], $filters['source_id']));

        return SnapshotResource::collection(
            BiSnapshot::query()
                ->select(['id', 'tenant_id', 'source_type', 'source_id', 'taken_by', 'taken_on', 'trigger', 'status', 'error', 'row_count', 'created_at'])
                ->where('source_type', $filters['source_type'])->where('source_id', $filters['source_id'])
                ->orderByDesc('taken_on')->orderByDesc('created_at')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/business-intelligence/snapshots', operationId: 'storeBiSnapshot', description: 'Takes a snapshot now: runs the saved query, or every widget of the dashboard, with the permissions of the caller and stores the figures. Only the most recent snapshots of a source are kept.', summary: 'Take a snapshot', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BiSnapshotRequest')), tags: ['Business Intelligence'], responses: [
        new OA\Response(response: 201, description: 'Snapshot taken', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BiSnapshot')])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BiUnprocessable', response: 422),
    ])]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['source_type' => ['required', Rule::in([BiSnapshot::SavedQuery, BiSnapshot::Dashboard])], 'source_id' => ['required', 'uuid']]);
        $source = $this->source($data['source_type'], $data['source_id']);
        $this->authorize('view', $source);
        $snapshot = $source instanceof BiSavedQuery
            ? $this->snapshots->takeForQuery($source, $this->currentUser())
            : $this->snapshots->takeForDashboard($source, $this->currentUser());

        $snapshot->offsetUnset('payload');

        return (new SnapshotResource($snapshot))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/business-intelligence/snapshots/{id}', operationId: 'showBiSnapshot', description: 'A snapshot with the figures it stored.', summary: 'Show a snapshot', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], parameters: [new OA\Parameter(ref: '#/components/parameters/BiId')], responses: [
        new OA\Response(response: 200, description: 'Snapshot', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BiSnapshot')])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
    ])]
    public function show(string $id): SnapshotResource
    {
        $snapshot = BiSnapshot::query()->findOrFail($id);
        $this->authorize('view', $this->source($snapshot->source_type, $snapshot->source_id));

        return new SnapshotResource($snapshot);
    }

    #[OA\Get(path: '/api/v1/business-intelligence/snapshots/{id}/compare', operationId: 'compareBiSnapshot', description: 'Compares a snapshot of a saved query with the figures of today: for each combination of dimension values, the value then, the value now and the change of every measure. The query runs with the permissions of the caller.', summary: 'Compare a snapshot with today', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], parameters: [new OA\Parameter(ref: '#/components/parameters/BiId')], responses: [
        new OA\Response(response: 200, description: 'Comparison', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BiSnapshotComparison')])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BiUnprocessable', response: 422),
    ])]
    public function compare(string $id): JsonResponse
    {
        $snapshot = BiSnapshot::query()->findOrFail($id);
        abort_unless($snapshot->source_type === BiSnapshot::SavedQuery && $snapshot->status === BiSnapshot::Ok, 422, __('bi_errors.snapshot.not_comparable'));
        $query = $this->source($snapshot->source_type, $snapshot->source_id);
        $this->authorize('view', $query);

        return response()->json(['data' => $this->snapshots->compareWithCurrent($snapshot, $query, $this->currentUser())]);
    }

    #[OA\Delete(path: '/api/v1/business-intelligence/snapshots/{id}', operationId: 'deleteBiSnapshot', description: 'Delete a snapshot. Allowed to the member who took it and to owners and administrators of the company.', summary: 'Delete a snapshot', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], parameters: [new OA\Parameter(ref: '#/components/parameters/BiId')], responses: [
        new OA\Response(response: 204, description: 'Snapshot deleted'),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $snapshot = BiSnapshot::query()->findOrFail($id);
        $this->authorize('delete', $snapshot);
        $snapshot->delete();

        return response()->noContent();
    }

    private function source(string $type, string $id): BiSavedQuery|BiDashboard
    {
        return $type === BiSnapshot::SavedQuery ? BiSavedQuery::query()->findOrFail($id) : BiDashboard::query()->findOrFail($id);
    }
}
