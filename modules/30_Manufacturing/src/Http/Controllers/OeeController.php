<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\Manufacturing\Enums\OeeCalculationGranularity;
use Modules\Manufacturing\Http\Controllers\Concerns\InteractsWithManufacturing;
use Modules\Manufacturing\Http\Requests\OeeQueryRequest;
use Modules\Manufacturing\Http\Requests\RecordOeeLogRequest;
use Modules\Manufacturing\Http\Resources\MfgOeeLogResource;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\Manufacturing\Services\ProductionEngineering\OeeCalculator;
use Modules\Manufacturing\Services\ProductionEngineering\ProductionEngineeringService;
use OpenApi\Attributes as OA;

/**
 * Overall equipment effectiveness of work centres and machines, and the production execution logs behind it.
 */
final class OeeController extends Controller
{
    use InteractsWithManufacturing;

    public function __construct(
        private readonly OeeCalculator $oee,
        private readonly ProductionEngineeringService $engineering,
    ) {}

    /**
     * OEE of the period with targets, big losses and machine telemetry status (no series).
     */
    #[OA\Get(path: '/api/v1/manufacturing/oee/summary', operationId: 'summaryMfgOee', summary: 'OEE summary', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'], responses: [new OA\Response(response: 200, description: 'OEE summary'), new OA\Response(response: 422, description: 'Validation error')])]
    public function summary(OeeQueryRequest $request): JsonResponse
    {
        $result = $this->compute($request, OeeCalculationGranularity::CustomRange);
        unset($result['series']);

        return response()->json(['data' => $result]);
    }

    /**
     * OEE per bucket (hour, shift, day, week or month) of the period.
     */
    #[OA\Get(path: '/api/v1/manufacturing/oee/timeseries', operationId: 'timeseriesMfgOee', summary: 'OEE time series', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'], responses: [new OA\Response(response: 200, description: 'OEE time series'), new OA\Response(response: 422, description: 'Validation error')])]
    public function timeseries(OeeQueryRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->compute($request, OeeCalculationGranularity::Daily)]);
    }

    /**
     * Records a production execution log.
     */
    #[OA\Post(path: '/api/v1/manufacturing/oee/logs', operationId: 'storeMfgOeeLog', summary: 'Record OEE log', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'], responses: [new OA\Response(response: 201, description: 'Log recorded'), new OA\Response(response: 422, description: 'Validation error')])]
    public function storeLog(RecordOeeLogRequest $request): JsonResponse
    {
        return (new MfgOeeLogResource($this->engineering->recordLog($this->tenantId(), $request->validated())))->response()->setStatusCode(201);
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(OeeQueryRequest $request, OeeCalculationGranularity $default): array
    {
        $data = $request->validated();
        $machine = isset($data['machine_center_id']) ? $this->owned(MfgMachineCenter::class, (string) $data['machine_center_id']) : null;
        /** @var MfgWorkCenter $workCenter */
        $workCenter = $this->owned(MfgWorkCenter::class, (string) ($data['work_center_id'] ?? $machine?->work_center_id));

        return $this->oee->calculate(
            $workCenter,
            $machine,
            CarbonImmutable::parse((string) $data['from_datetime']),
            CarbonImmutable::parse((string) $data['to_datetime']),
            OeeCalculationGranularity::tryFrom((string) ($data['granularity'] ?? '')) ?? $default,
        );
    }
}
