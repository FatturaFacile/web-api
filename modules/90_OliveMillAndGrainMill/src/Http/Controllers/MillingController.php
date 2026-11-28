<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\OliveMillAndGrainMill\Application\Commands\CompleteMillingCycleCommand;
use Modules\OliveMillAndGrainMill\Application\Commands\StartMillingCycleCommand;
use Modules\OliveMillAndGrainMill\Application\Handlers\CompleteMillingCycleHandler;
use Modules\OliveMillAndGrainMill\Application\Handlers\StartMillingCycleHandler;
use Modules\OliveMillAndGrainMill\Application\Queries\MillQueries;
use Modules\OliveMillAndGrainMill\Http\Controllers\Concerns\InteractsWithOliveMill;
use Modules\OliveMillAndGrainMill\Http\Requests\CompleteCycleRequest;
use Modules\OliveMillAndGrainMill\Http\Requests\StartCycleRequest;
use Modules\OliveMillAndGrainMill\Http\Resources\CycleResource;
use Modules\OliveMillAndGrainMill\Http\Resources\DeliveryResource;
use Modules\OliveMillAndGrainMill\Models\MillingCycle;
use OpenApi\Attributes as OA;

/**
 * Milling calendar (slots per line) and the milling cycles: start, completion with the yield and the routing of the product.
 */
final class MillingController extends Controller
{
    use ApiResponse;
    use InteractsWithOliveMill;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/olive-mill/milling-schedule', operationId: 'oliveMillSchedule', summary: 'Hopper bookings of a period grouped by milling line', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'Schedule')])]
    public function schedule(Request $request, MillQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', MillingCycle::class);

        $from = Carbon::parse((string) $request->query('from', now()->toDateString()))->startOfDay();
        $to = Carbon::parse((string) $request->query('to', $from->copy()->addDays(6)->toDateString()))->endOfDay();
        $line = $request->query('line');
        $bookings = $queries->schedule($from, $to, is_string($line) && $line !== '' ? $line : null);

        $lines = [];
        foreach ($bookings->groupBy(static fn ($d): string => (string) $d->hopper_line) as $name => $items) {
            $lines[] = ['line' => (string) $name, 'bookings' => DeliveryResource::collection($items)];
        }

        return $this->successResponse(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'lines' => $lines], $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/olive-mill/milling-cycles', operationId: 'oliveMillCyclesIndex', summary: 'Milling cycles (filter by status, line, delivery, period)', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'Cycles')])]
    public function index(MillQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', MillingCycle::class);

        return $this->paged($queries->cycles($this->filters(), $this->perPage()), CycleResource::class);
    }

    #[OA\Get(path: '/api/v1/olive-mill/milling-cycles/{id}', operationId: 'oliveMillCyclesShow', summary: 'One milling cycle', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'Cycle')])]
    public function show(string $id, MillQueries $queries): JsonResponse
    {
        $cycle = $queries->cycle($id);
        Gate::authorize('view', $cycle);

        return $this->successResponse(new CycleResource($cycle), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/olive-mill/milling-cycles', operationId: 'oliveMillCyclesStore', summary: 'Put a weighed lot in the hopper: the cycle starts', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 201, description: 'Cycle')])]
    public function store(StartCycleRequest $request, StartMillingCycleHandler $handler): JsonResponse
    {
        Gate::authorize('create', MillingCycle::class);

        $cycle = $handler->handle(StartMillingCycleCommand::fromArray($request->validated(), $this->userId()));

        return $this->createdResponse(new CycleResource($cycle), $this->message('cycle_started'));
    }

    #[OA\Post(path: '/api/v1/olive-mill/milling-cycles/{id}/complete', operationId: 'oliveMillCyclesComplete', summary: 'Complete a cycle: yield, lab values, by-products and routing of the oil', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'Completed cycle')])]
    public function complete(CompleteCycleRequest $request, string $id, CompleteMillingCycleHandler $handler): JsonResponse
    {
        Gate::authorize('update', MillingCycle::query()->findOrFail($id));

        $cycle = $handler->handle(CompleteMillingCycleCommand::fromArray(['cycle_id' => $id] + $request->validated(), $this->userId()));

        return $this->successResponse(new CycleResource($cycle), $this->message('cycle_completed'));
    }
}
