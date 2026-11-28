<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\DairyCheeseManufacturing\Application\Commands\SellWheelsCommand;
use Modules\DairyCheeseManufacturing\Application\Commands\SellWheelsHandler;
use Modules\DairyCheeseManufacturing\Http\Requests\GradeWheelRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\MoveWheelRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\SellWheelsRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\ViewRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\WeighWheelRequest;
use Modules\DairyCheeseManufacturing\Http\Resources\WheelResource;
use Modules\DairyCheeseManufacturing\Models\CheeseAgingWheel;
use Modules\DairyCheeseManufacturing\Services\WheelService;
use OpenApi\Attributes as OA;

/**
 * Cheese wheels of the aging cellar (dual inventory: count and weight).
 */
final class WheelController extends Controller
{
    use ApiResponse;
    use DairyControllerSupport;

    public function __construct(private readonly WheelService $wheels) {}

    #[OA\Get(path: '/api/v1/dairy-cheese/wheels', operationId: 'dairyCheeseWheelsList', summary: 'Wheels with dual inventory totals (paginated)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 200, description: 'Wheels')])]
    public function index(ViewRequest $request): JsonResponse
    {
        $query = $this->wheels->query($request->validated());
        $totals = $this->wheels->totals($query);
        $page = $query->paginate($this->perPage($request));

        return $this->successResponse(WheelResource::collection($page->items()), 'OK', 200, $this->pageMeta($page) + ['totals' => $totals]);
    }

    #[OA\Get(path: '/api/v1/dairy-cheese/wheels/{id}', operationId: 'dairyCheeseWheelsShow', summary: 'Show a wheel', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Wheel')])]
    public function show(string $id): JsonResponse
    {
        $this->view();

        return $this->successResponse(new WheelResource(CheeseAgingWheel::query()->with(['batch', 'rack'])->findOrFail($id)));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/wheels/{id}/move', operationId: 'dairyCheeseWheelsMove', summary: 'Move a wheel to a shelf', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Wheel moved')])]
    public function move(MoveWheelRequest $request, string $id): JsonResponse
    {
        $wheel = $this->wheels->move(CheeseAgingWheel::query()->with('batch')->findOrFail($id), (string) $request->validated('rack_id'), $request->validated('position') === null ? null : (int) $request->validated('position'));

        return $this->successResponse(new WheelResource($wheel), $this->message('saved'));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/wheels/{id}/weigh', operationId: 'dairyCheeseWheelsWeigh', summary: 'Record a weighing of the wheel', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Wheel weighed')])]
    public function weigh(WeighWheelRequest $request, string $id): JsonResponse
    {
        $wheel = $this->wheels->weigh(CheeseAgingWheel::query()->with('batch')->findOrFail($id), (float) $request->validated('weight_kg'));

        return $this->successResponse(new WheelResource($wheel), $this->message('saved'));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/wheels/{id}/grade', operationId: 'dairyCheeseWheelsGrade', summary: 'Consortium expert grading and fire-branding', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Wheel graded')])]
    public function grade(GradeWheelRequest $request, string $id): JsonResponse
    {
        $wheel = $this->wheels->grade(
            CheeseAgingWheel::query()->with('batch')->findOrFail($id), (string) $request->validated('grade'), $request->boolean('branded'), $request->validated('notes'), $this->userId($request),
        );

        return $this->successResponse(new WheelResource($wheel), $this->message('wheel_graded'));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/wheels/sell', operationId: 'dairyCheeseWheelsSell', summary: 'Sale checkout: decrement the wheel count and record the weighed kg against the book weight', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 200, description: 'Sold wheels and variance')])]
    public function sell(SellWheelsRequest $request, SellWheelsHandler $handler): JsonResponse
    {
        $result = $handler->handle(new SellWheelsCommand(
            array_values($request->validated('wheel_ids')), $request->validated('customer_id'), (string) $request->validated('customer_name'), (array) $request->validated('weights'), $request->validated('invoice_ref'),
        ));

        return $this->successResponse([
            'sold' => WheelResource::collection($result['sold'])->resolve(), 'total_kg' => $result['total_kg'], 'total_variance_kg' => $result['total_variance_kg'],
        ], $this->message('wheels_sold'));
    }
}
