<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\CrisisMonitoring\Application\Commands\RunCrisisStressTestSimulationCommand;
use Modules\CrisisMonitoring\Application\Handlers\RunCrisisStressTestSimulationHandler;
use Modules\CrisisMonitoring\Application\Services\CrisisTexts;
use Modules\CrisisMonitoring\Http\Controllers\Concerns\ResolvesCrisisContext;
use Modules\CrisisMonitoring\Http\Requests\StressTestRequest;
use OpenApi\Attributes as OA;

/**
 * The stress test simulator. It is a read operation: nothing is saved.
 */
final class StressTestController extends Controller
{
    use ApiResponse;
    use ResolvesCrisisContext;

    #[OA\Post(path: '/api/v1/crisis-monitoring/stress-test', operationId: 'runCrisisMonitoringStressTest', summary: 'Run a what-if shock', description: 'Revenue drop, collection delay (DSO shift), rate hike on the variable debt and an extraordinary outflow, against the live figures. Returns the baseline and the stressed picture side by side; nothing is saved.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Crisis Monitoring'], responses: [new OA\Response(response: 200, description: 'Baseline, stressed and deltas')])]
    public function run(StressTestRequest $request, RunCrisisStressTestSimulationHandler $handler): JsonResponse
    {
        return $this->successResponse($handler->handle(new RunCrisisStressTestSimulationCommand($this->tenant(), $request->shock(), $request->asOf())), CrisisTexts::get('crisisApi.message.stress', CrisisTexts::locale()));
    }
}
