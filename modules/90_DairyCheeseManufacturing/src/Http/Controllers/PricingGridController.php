<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\DairyCheeseManufacturing\Exceptions\DairyCheeseException;
use Modules\DairyCheeseManufacturing\Http\Requests\SavePricingGridRequest;
use Modules\DairyCheeseManufacturing\Http\Resources\PricingGridResource;
use Modules\DairyCheeseManufacturing\Models\Breeder;
use Modules\DairyCheeseManufacturing\Models\MonthlySettlement;
use Modules\DairyCheeseManufacturing\Models\PricingGrid;
use OpenApi\Attributes as OA;

/**
 * Quality bonus / penalty grids with the cheese-milk supplements. Shared templates (no tenant) are read-only.
 */
final class PricingGridController extends Controller
{
    use ApiResponse;
    use DairyControllerSupport;

    #[OA\Get(path: '/api/v1/dairy-cheese/pricing-grids', operationId: 'dairyCheesePricingGridsList', summary: 'List the pricing grids (own and shared templates)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 200, description: 'Grids')])]
    public function index(): JsonResponse
    {
        $this->view();

        return $this->successResponse(PricingGridResource::collection(PricingGrid::query()->orderBy('name')->get()));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/pricing-grids', operationId: 'dairyCheesePricingGridsCreate', summary: 'Create a pricing grid', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 201, description: 'Grid created')])]
    public function store(SavePricingGridRequest $request): JsonResponse
    {
        $grid = PricingGrid::query()->create($this->attributes($request) + ['created_by' => $this->userId($request)]);

        return $this->successResponse(new PricingGridResource($grid), $this->message('saved'), 201);
    }

    #[OA\Get(path: '/api/v1/dairy-cheese/pricing-grids/{id}', operationId: 'dairyCheesePricingGridsShow', summary: 'Show a pricing grid', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Grid')])]
    public function show(string $id): JsonResponse
    {
        $this->view();

        return $this->successResponse(new PricingGridResource(PricingGrid::query()->findOrFail($id)));
    }

    #[OA\Put(path: '/api/v1/dairy-cheese/pricing-grids/{id}', operationId: 'dairyCheesePricingGridsUpdate', summary: 'Update a pricing grid', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Grid updated')])]
    public function update(SavePricingGridRequest $request, string $id): JsonResponse
    {
        $grid = $this->own($id);

        if (MonthlySettlement::query()->where('grid_id', $grid->id)->where('status', '!=', 'draft')->exists()) {
            throw new DairyCheeseException('The grid is used by approved settlements.', 'grid_in_use');
        }

        $grid->fill($this->attributes($request) + ['updated_by' => $this->userId($request)])->save();

        return $this->successResponse(new PricingGridResource($grid), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/dairy-cheese/pricing-grids/{id}', operationId: 'dairyCheesePricingGridsDelete', summary: 'Delete a pricing grid that no settlement uses', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Grid deleted')])]
    public function destroy(Request $request, string $id): JsonResponse
    {
        Gate::authorize('settle', Breeder::class);
        $grid = $this->own($id);

        if (MonthlySettlement::query()->where('grid_id', $grid->id)->exists()) {
            throw new DairyCheeseException('The grid is used by settlements.', 'grid_in_use');
        }

        $grid->delete();

        return $this->successResponse(null, $this->message('deleted'));
    }

    private function own(string $id): PricingGrid
    {
        $grid = PricingGrid::query()->findOrFail($id);

        if ($grid->tenant_id === null) {
            throw new DairyCheeseException('Shared templates cannot be changed: duplicate the grid first.', 'template_read_only', 403);
        }

        return $grid;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(SavePricingGridRequest $request): array
    {
        $data = $request->validated();
        $data['cheese_milk_supplement_per_kg'] ??= 0;
        $data['dop_premium_per_kg'] ??= 0;
        $data['tiers'] = array_values(array_map(static fn (array $t): array => [
            'component' => $t['component'], 'from_value' => $t['from_value'] ?? null, 'to_value' => $t['to_value'] ?? null, 'adjustment_per_kg' => $t['adjustment_per_kg'],
        ], $data['tiers'] ?? []));

        return $data;
    }
}
