<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Beekeeping\Application\Commands\RecordHiveInspectionCommand;
use Modules\Beekeeping\Application\Commands\RecordHiveInspectionHandler;
use Modules\Beekeeping\Exceptions\BeekeepingException;
use Modules\Beekeeping\Http\Controllers\Concerns\ResolvesBeekeepingContext;
use Modules\Beekeeping\Http\Requests\RecordInspectionRequest;
use Modules\Beekeeping\Http\Resources\InspectionResource;
use Modules\Beekeeping\Models\Hive;
use Modules\Beekeeping\Models\Inspection;
use Modules\Beekeeping\Services\InspectionService;
use OpenApi\Attributes as OA;

/**
 * Hive inspections: single (`hives/{id}/inspections`) or the same observations on several hives of an apiary (`inspections/batch`).
 */
final class InspectionController extends Controller
{
    use ApiResponse;
    use ResolvesBeekeepingContext;

    public function __construct(private readonly RecordHiveInspectionHandler $record, private readonly InspectionService $inspections) {}

    #[OA\Get(path: '/api/v1/beekeeping/hives/{id}/inspections', operationId: 'beekeepingInspectionsList', summary: 'Inspection history of a hive', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Inspections')])]
    public function index(string $id): JsonResponse
    {
        $this->authorizeRead();
        Hive::query()->findOrFail($id);

        return $this->successResponse(InspectionResource::collection(Inspection::query()->where('hive_id', $id)->orderByDesc('inspected_at')->limit(100)->get()));
    }

    #[OA\Post(path: '/api/v1/beekeeping/hives/{id}/inspections', operationId: 'beekeepingInspectionsCreate', summary: 'Record an inspection of a hive', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Inspection recorded')])]
    public function store(RecordInspectionRequest $request, string $id): JsonResponse
    {
        $hive = Hive::query()->findOrFail($id);
        $inspection = $this->record->handle(new RecordHiveInspectionCommand($hive, $this->payload($request), $this->userId($request)));

        return $this->successResponse(new InspectionResource($inspection), $this->message('inspection_recorded'), 201);
    }

    #[OA\Post(path: '/api/v1/beekeeping/inspections/batch', operationId: 'beekeepingInspectionsBatch', summary: 'Record the same inspection on several hives of an apiary', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 201, description: 'Inspections recorded')])]
    public function batch(RecordInspectionRequest $request): JsonResponse
    {
        $apiaryId = $request->validated('apiary_id');
        $hiveIds = (array) $request->validated('hive_ids', []);

        if (! is_string($apiaryId) || $hiveIds === []) {
            throw new BeekeepingException('A batch inspection needs the apiary and its hives.', 'batch_inspection_incomplete');
        }

        $inspections = $this->inspections->recordBatch($apiaryId, array_values($hiveIds), $this->payload($request), $this->userId($request));

        return $this->successResponse(InspectionResource::collection(collect($inspections)), $this->message('inspections_recorded'), 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(RecordInspectionRequest $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        unset($data['apiary_id'], $data['hive_ids']);

        return $data;
    }
}
