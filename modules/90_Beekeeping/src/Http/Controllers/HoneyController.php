<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Beekeeping\Application\Commands\CreateExtractionBatchCommand;
use Modules\Beekeeping\Application\Commands\CreateExtractionBatchHandler;
use Modules\Beekeeping\Application\Commands\RecordJarringRunCommand;
use Modules\Beekeeping\Application\Commands\RecordJarringRunHandler;
use Modules\Beekeeping\Http\Controllers\Concerns\ResolvesBeekeepingContext;
use Modules\Beekeeping\Http\Requests\CreateBatchRequest;
use Modules\Beekeeping\Http\Requests\RecordAnalysisRequest;
use Modules\Beekeeping\Http\Requests\RecordJarringRequest;
use Modules\Beekeeping\Http\Requests\SaveIntakeRequest;
use Modules\Beekeeping\Http\Resources\BatchResource;
use Modules\Beekeeping\Http\Resources\IntakeResource;
use Modules\Beekeeping\Http\Resources\JarringRunResource;
use Modules\Beekeeping\Models\ExtractionBatch;
use Modules\Beekeeping\Models\SuperIntake;
use Modules\Beekeeping\Services\HoneyService;
use OpenApi\Attributes as OA;

/**
 * Honey room: super intake, extraction batches (HACCP lots), maturation analysis and jarring runs.
 */
final class HoneyController extends Controller
{
    use ApiResponse;
    use ResolvesBeekeepingContext;

    public function __construct(
        private readonly HoneyService $honey,
        private readonly CreateExtractionBatchHandler $createBatch,
        private readonly RecordJarringRunHandler $recordJarring,
    ) {}

    #[OA\Get(path: '/api/v1/beekeeping/intakes', operationId: 'beekeepingIntakesList', summary: 'Super intakes, optionally only those not yet extracted', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 200, description: 'Intakes')])]
    public function intakes(Request $request): JsonResponse
    {
        $this->authorizeRead();

        return $this->successResponse(IntakeResource::collection(SuperIntake::query()->when($request->boolean('pending'), static fn ($query) => $query->whereNull('extraction_batch_id'))->orderByDesc('harvested_on')->get()));
    }

    #[OA\Post(path: '/api/v1/beekeeping/intakes', operationId: 'beekeepingIntakesCreate', summary: 'Record a super intake', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 201, description: 'Intake recorded')])]
    public function storeIntake(SaveIntakeRequest $request): JsonResponse
    {
        return $this->successResponse(new IntakeResource($this->honey->recordIntake($request->validated() + ['created_by' => $this->userId($request)])), $this->message('intake_recorded'), 201);
    }

    #[OA\Get(path: '/api/v1/beekeeping/batches', operationId: 'beekeepingBatchesList', summary: 'Extraction batches', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 200, description: 'Batches')])]
    public function batches(): JsonResponse
    {
        $this->authorizeRead();

        return $this->successResponse(BatchResource::collection(ExtractionBatch::query()->with('jarringRuns')->orderByDesc('extracted_on')->get()));
    }

    #[OA\Post(path: '/api/v1/beekeeping/batches', operationId: 'beekeepingBatchesCreate', summary: 'Open an extraction batch from super intakes', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 201, description: 'Batch created')])]
    public function storeBatch(CreateBatchRequest $request): JsonResponse
    {
        $batch = $this->createBatch->handle(new CreateExtractionBatchCommand($request->validated()));

        return $this->successResponse(new BatchResource($batch->load(['intakes', 'jarringRuns'])), $this->message('batch_created'), 201);
    }

    #[OA\Get(path: '/api/v1/beekeeping/batches/{id}', operationId: 'beekeepingBatchesShow', summary: 'Show an extraction batch with intakes and jarring runs', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Batch')])]
    public function showBatch(string $id): JsonResponse
    {
        $this->authorizeRead();

        return $this->successResponse(new BatchResource(ExtractionBatch::query()->with(['intakes', 'jarringRuns'])->findOrFail($id)));
    }

    #[OA\Put(path: '/api/v1/beekeeping/batches/{id}/analysis', operationId: 'beekeepingBatchesAnalysis', summary: 'Record maturation and laboratory analysis', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Analysis recorded')])]
    public function analysis(RecordAnalysisRequest $request, string $id): JsonResponse
    {
        $batch = $this->honey->recordAnalysis(ExtractionBatch::query()->findOrFail($id), $request->validated());

        return $this->successResponse(new BatchResource($batch->load(['intakes', 'jarringRuns'])), $this->message('analysis_recorded'));
    }

    #[OA\Post(path: '/api/v1/beekeeping/batches/{id}/jarring', operationId: 'beekeepingJarringCreate', summary: 'Record a jarring run and deduct the packaging stock', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Jarring run recorded')])]
    public function jar(RecordJarringRequest $request, string $id): JsonResponse
    {
        $run = $this->recordJarring->handle(new RecordJarringRunCommand(ExtractionBatch::query()->findOrFail($id), $request->validated()));

        return $this->successResponse(new JarringRunResource($run), $this->message('jarring_recorded'), 201);
    }
}
