<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Sustainability\Http\Controllers\Concerns\InteractsWithSustainability;
use Modules\Sustainability\Http\Requests\ActivityRecordRequest;
use Modules\Sustainability\Http\Requests\BulkActivityRecordRequest;
use Modules\Sustainability\Http\Resources\ActivityRecordResource;
use Modules\Sustainability\Models\EsgActivityRecord;
use Modules\Sustainability\Services\ActivityDataService;
use Modules\Sustainability\Support\ActivityCatalog;
use OpenApi\Attributes as OA;

/**
 * Activity data: the measured or estimated quantities the GHG inventory is computed from.
 */
final class ActivityRecordController extends Controller
{
    use InteractsWithSustainability;

    public function __construct(private readonly ActivityDataService $activities) {}

    #[OA\Get(path: '/api/v1/sustainability/activity-records', operationId: 'listEsgActivityRecords', description: 'Paginated activity data, newest first. Filters: from, to (Y-m-d), scope (1|2|3), activity_type, data_quality (measured|estimated).', summary: 'List activity data', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], responses: [
        new OA\Response(response: 200, description: 'Activity records', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SustainabilityActivityRecord'))])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', EsgActivityRecord::class);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'scope' => ['nullable', Rule::in([1, 2, 3])],
            'activity_type' => ['nullable', Rule::in(ActivityCatalog::types())],
            'data_quality' => ['nullable', Rule::in(ActivityCatalog::DataQualities)],
        ]);

        return ActivityRecordResource::collection(
            EsgActivityRecord::query()
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['from']), static fn ($query) => $query->whereDate('activity_date', '>=', $filters['from']))
                ->when(isset($filters['to']), static fn ($query) => $query->whereDate('activity_date', '<=', $filters['to']))
                ->when(isset($filters['scope']), static fn ($query) => $query->where('scope', (int) $filters['scope']))
                ->when(isset($filters['activity_type']), static fn ($query) => $query->where('activity_type', $filters['activity_type']))
                ->when(isset($filters['data_quality']), static fn ($query) => $query->where('data_quality', $filters['data_quality']))
                ->orderByDesc('activity_date')
                ->orderByDesc('created_at')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/sustainability/activity-records', operationId: 'storeEsgActivityRecord', description: 'Record activity data. The emission factor is named (emission_factor_id) or chosen from the activity type and region (most recent active factor, falling back to the EU and global ones). CO2e is quantity x factor with exact decimals and the factor version is frozen on the record. A date inside a closed period is refused.', summary: 'Record activity data', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SustainabilityActivityRecordRequest')), tags: ['Sustainability'], responses: [
        new OA\Response(response: 201, description: 'Record created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityActivityRecord')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function store(ActivityRecordRequest $request): JsonResponse
    {
        $this->authorize('create', EsgActivityRecord::class);

        return (new ActivityRecordResource($this->activities->create($this->tenantId(), $request->validated(), $this->userId())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/sustainability/activity-records/bulk', operationId: 'bulkStoreEsgActivityRecords', description: 'Record up to 500 lines in one transaction; one invalid line refuses the whole batch (errors are keyed lines.N.field).', summary: 'Record activity data in bulk', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SustainabilityBulkActivityRequest')), tags: ['Sustainability'], responses: [
        new OA\Response(response: 201, description: 'Records created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SustainabilityActivityRecord'))])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function bulkStore(BulkActivityRecordRequest $request): JsonResponse
    {
        $this->authorize('create', EsgActivityRecord::class);
        $records = $this->activities->createMany($this->tenantId(), $request->validated('lines'), $this->userId());

        return ActivityRecordResource::collection(collect($records))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/sustainability/activity-records/{id}', operationId: 'showEsgActivityRecord', description: 'Return a line of activity data with its frozen factor.', summary: 'Show activity data', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityActivityRecord')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
    ])]
    public function show(string $id): ActivityRecordResource
    {
        $record = EsgActivityRecord::query()->findOrFail($id);
        $this->authorize('view', $record);

        return new ActivityRecordResource($record);
    }

    #[OA\Put(path: '/api/v1/sustainability/activity-records/{id}', operationId: 'updateEsgActivityRecord', description: 'Change a line of activity data; CO2e is recomputed when the quantity, the unit or the factor change. Refused when the date is in a closed period.', summary: 'Update activity data', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SustainabilityActivityRecordRequest')), tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Record updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityActivityRecord')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function update(ActivityRecordRequest $request, string $id): ActivityRecordResource
    {
        $record = EsgActivityRecord::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new ActivityRecordResource($this->activities->update($record, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/sustainability/activity-records/{id}', operationId: 'deleteEsgActivityRecord', description: 'Delete a line of activity data. Refused when the date is in a closed period.', summary: 'Delete activity data', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 204, description: 'Record deleted'),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $record = EsgActivityRecord::query()->findOrFail($id);
        $this->authorize('delete', $record);
        $this->activities->delete($record);

        return response()->noContent();
    }
}
