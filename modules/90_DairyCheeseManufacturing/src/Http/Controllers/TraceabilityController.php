<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\DairyCheeseManufacturing\Application\Commands\GenerateRecallReportCommand;
use Modules\DairyCheeseManufacturing\Application\Commands\GenerateRecallReportHandler;
use Modules\DairyCheeseManufacturing\Application\Queries\TraceLineageHandler;
use Modules\DairyCheeseManufacturing\Application\Queries\TraceLineageQuery;
use Modules\DairyCheeseManufacturing\Http\Requests\RecallReportRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\TraceRequest;
use Modules\DairyCheeseManufacturing\Http\Resources\VatBatchResource;
use Modules\DairyCheeseManufacturing\Http\Resources\WheelResource;
use OpenApi\Attributes as OA;

/**
 * HACCP bidirectional traceability and recall.
 */
final class TraceabilityController extends Controller
{
    use ApiResponse;
    use DairyControllerSupport;

    #[OA\Get(path: '/api/v1/dairy-cheese/traceability', operationId: 'dairyCheeseTraceability', summary: 'Forward or backward lineage tree of a delivery, analysis, vat batch or wheel', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 200, description: 'Lineage tree and summary')])]
    public function trace(TraceRequest $request, TraceLineageHandler $handler): JsonResponse
    {
        $v = $request->validated();

        return $this->successResponse($handler->handle(new TraceLineageQuery((string) $v['direction'], (string) $v['type'], $v['id'] ?? null, $v['serial'] ?? null)));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/traceability/recall-report', operationId: 'dairyCheeseRecallReport', summary: 'Recall report of everything affected; optionally puts the wheels on recall', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 200, description: 'Recall report')])]
    public function recall(RecallReportRequest $request, GenerateRecallReportHandler $handler): JsonResponse
    {
        $v = $request->validated();
        $result = $handler->handle(new GenerateRecallReportCommand((string) $v['type'], $v['id'] ?? null, $v['serial'] ?? null, (string) $v['reason'], $request->boolean('apply'), $this->userId($request)));

        return $this->successResponse([
            'report_id' => $result['report']->id,
            'generated_at' => $result['report']->generated_at->toIso8601String(),
            'applied' => (bool) $result['report']->applied,
            'reason' => $result['report']->reason,
            'summary' => $result['summary'],
            'affected_wheels' => WheelResource::collection($result['wheels'])->resolve(),
            'affected_batches' => VatBatchResource::collection($result['batches'])->resolve(),
            'customers' => $result['customers'],
        ], $this->message('recall_generated'));
    }
}
