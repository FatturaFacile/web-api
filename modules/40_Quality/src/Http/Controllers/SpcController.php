<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Quality\Http\Controllers\Concerns\InteractsWithQuality;
use Modules\Quality\Http\Requests\SpcCharacteristicRequest;
use Modules\Quality\Http\Requests\SpcSampleRequest;
use Modules\Quality\Http\Resources\SpcCharacteristicResource;
use Modules\Quality\Http\Resources\SpcSampleResource;
use Modules\Quality\Models\QmsSpcCharacteristic;
use Modules\Quality\Models\QmsSpcSample;
use Modules\Quality\Services\SpcService;
use OpenApi\Attributes as OA;

/**
 * Statistical process control: characteristics, subgroup samples, X-bar/R chart data, capability and rule violations.
 */
final class SpcController extends Controller
{
    use InteractsWithQuality;

    public function __construct(private readonly SpcService $spc) {}

    #[OA\Get(path: '/api/v1/quality/spc/characteristics', operationId: 'listQmsSpcCharacteristics', description: 'Paginated SPC characteristics by code. Filter: status.', summary: 'List SPC characteristics', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'archived'])),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Characteristics', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/QmsSpcCharacteristic'))])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', QmsSpcCharacteristic::class);

        return SpcCharacteristicResource::collection(
            QmsSpcCharacteristic::query()->where('tenant_id', $this->tenantId())
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->orderBy('code')->gridPaginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/quality/spc/characteristics', operationId: 'storeQmsSpcCharacteristic', description: 'Defines a characteristic with its specification limits and subgroup size (2 to 10).', summary: 'Create an SPC characteristic', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsSpcCharacteristicRequest')), tags: ['Quality'], responses: [
        new OA\Response(response: 201, description: 'Characteristic created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsSpcCharacteristic')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function store(SpcCharacteristicRequest $request): JsonResponse
    {
        $this->authorize('create', QmsSpcCharacteristic::class);

        return (new SpcCharacteristicResource($this->spc->createCharacteristic($this->tenantId(), $request->validated())))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/quality/spc/characteristics/{id}', operationId: 'showQmsSpcCharacteristic', description: 'A characteristic.', summary: 'Show an SPC characteristic', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Characteristic', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsSpcCharacteristic')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
    ])]
    public function show(string $id): SpcCharacteristicResource
    {
        $characteristic = $this->find($id);
        $this->authorize('view', $characteristic);

        return new SpcCharacteristicResource($characteristic);
    }

    #[OA\Put(path: '/api/v1/quality/spc/characteristics/{id}', operationId: 'updateQmsSpcCharacteristic', description: 'Changes a characteristic. The subgroup size is locked once samples exist; status archived stops new samples.', summary: 'Update an SPC characteristic', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsSpcCharacteristicRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Characteristic updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsSpcCharacteristic')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function update(SpcCharacteristicRequest $request, string $id): SpcCharacteristicResource
    {
        $characteristic = $this->find($id);
        $this->authorize('update', $characteristic);

        return new SpcCharacteristicResource($this->spc->updateCharacteristic($characteristic, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/quality/spc/characteristics/{id}', operationId: 'deleteQmsSpcCharacteristic', description: 'Soft-deletes a characteristic.', summary: 'Delete an SPC characteristic', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 204, description: 'Deleted'),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
    ])]
    public function destroy(string $id): JsonResponse
    {
        $characteristic = $this->find($id);
        $this->authorize('delete', $characteristic);
        $characteristic->delete();

        return response()->json(null, 204);
    }

    #[OA\Get(path: '/api/v1/quality/spc/characteristics/{id}/chart', operationId: 'getQmsSpcChart', description: 'X-bar/R control chart of the latest subgroups: points, center lines and control limits, capability (Cp, Cpk within subgroup sigma; Pp, Ppk overall sigma) and the Nelson rule violations. Limits are reliable from 20 subgroups (is_reliable).', summary: 'SPC chart data and capability', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/QualityId'),
        new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 2, maximum: 500), description: 'Latest subgroups to use, default 100'),
    ], responses: [
        new OA\Response(response: 200, description: 'Chart', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsSpcChart')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
    ])]
    public function chart(Request $request, string $id): JsonResponse
    {
        $characteristic = $this->find($id);
        $this->authorize('view', $characteristic);
        $limit = max(2, min(500, (int) $request->query('limit', 100)));

        return response()->json(['data' => $this->spc->chart($characteristic, $limit)]);
    }

    #[OA\Get(path: '/api/v1/quality/spc/characteristics/{id}/samples', operationId: 'listQmsSpcSamples', description: 'Paginated samples of a characteristic, newest first.', summary: 'List SPC samples', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/QualityId'),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Samples', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/QmsSpcSample'))])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
    ])]
    public function samples(string $id): AnonymousResourceCollection
    {
        $characteristic = $this->find($id);
        $this->authorize('view', $characteristic);

        return SpcSampleResource::collection(
            QmsSpcSample::query()->where('characteristic_id', $characteristic->id)->orderByDesc('sampled_at')->gridPaginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/quality/spc/characteristics/{id}/samples', operationId: 'storeQmsSpcSample', description: 'Records a subgroup (exactly subgroup_size measurements). The response lists the out-of-control rules the new point triggers; a violation is also written to the audit ledger.', summary: 'Record an SPC sample', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsSpcSampleRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 201, description: 'Sample recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsSpcSample'), new OA\Property(property: 'violations', type: 'array', items: new OA\Items(ref: '#/components/schemas/QmsSpcViolation'))])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function storeSample(SpcSampleRequest $request, string $id): JsonResponse
    {
        $characteristic = $this->find($id);
        $this->authorize('update', $characteristic);
        $result = $this->spc->addSample($characteristic, $request->validated(), $this->userId());

        return response()->json([
            'data' => (new SpcSampleResource($result['sample']))->resolve(),
            'violations' => $result['violations'],
        ], 201);
    }

    #[OA\Delete(path: '/api/v1/quality/spc/samples/{id}', operationId: 'deleteQmsSpcSample', description: 'Soft-deletes a sample entered by mistake.', summary: 'Delete an SPC sample', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 204, description: 'Deleted'),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
    ])]
    public function destroySample(string $id): JsonResponse
    {
        $sample = QmsSpcSample::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('delete', $sample);
        $sample->delete();

        return response()->json(null, 204);
    }

    private function find(string $id): QmsSpcCharacteristic
    {
        return QmsSpcCharacteristic::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
