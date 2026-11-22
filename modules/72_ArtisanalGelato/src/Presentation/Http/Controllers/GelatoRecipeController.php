<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ArtisanalGelato\Application\Commands\BalanceGelatoRecipeParametersCommand;
use Modules\ArtisanalGelato\Application\Handlers\BalanceGelatoRecipeParametersHandler;
use Modules\ArtisanalGelato\Application\Services\GelatoGanacheAndOrderService;
use Modules\ArtisanalGelato\Application\Services\GelatoRecipeService;
use Modules\ArtisanalGelato\Domain\Enums\RecipeCategory;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoBalancedRecipe;
use Modules\ArtisanalGelato\Presentation\Http\Controllers\Concerns\MapsGelatoErrors;
use Modules\ArtisanalGelato\Presentation\Http\Requests\BalanceGelatoRecipeRequest;
use Modules\ArtisanalGelato\Presentation\Http\Requests\GelatoRecipeActionRequest;
use Modules\ArtisanalGelato\Presentation\Http\Requests\SaveGelatoRecipeRequest;
use Modules\ArtisanalGelato\Presentation\Http\Resources\GelatoRecipeResource;
use OpenApi\Attributes as OA;

/**
 * Recipes: CRUD, the real-time balance preview and auto-balance solver, clone and batch scaling, and the ganache studio.
 */
final class GelatoRecipeController extends Controller
{
    use ApiResponse;
    use MapsGelatoErrors;

    #[OA\Get(path: '/api/v1/gelato/recipes', operationId: 'listGelatoRecipes', summary: 'Recipes', description: 'Optionally filtered by kind.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], responses: [new OA\Response(response: 200, description: 'The recipes')])]
    public function index(Request $request): JsonResponse
    {
        $query = GelatoBalancedRecipe::query()->orderBy('code');

        if ($request->filled('kind')) {
            $query->where('kind', (string) $request->query('kind'));
        }

        return $this->successResponse(GelatoRecipeResource::collection($query->limit(500)->get()));
    }

    #[OA\Get(path: '/api/v1/gelato/recipes/{id}', operationId: 'showGelatoRecipe', summary: 'A recipe with its lines', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The recipe'), new OA\Response(response: 404, description: 'Unknown recipe')])]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new GelatoRecipeResource(GelatoBalancedRecipe::query()->with('lines')->findOrFail($id)));
    }

    #[OA\Post(path: '/api/v1/gelato/recipes', operationId: 'createGelatoRecipe', summary: 'Create a recipe', description: 'The physico-chemical vector is computed and stored with the recipe.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], responses: [new OA\Response(response: 201, description: 'The recipe'), new OA\Response(response: 422, description: 'Invalid recipe')])]
    public function store(SaveGelatoRecipeRequest $request, GelatoRecipeService $service): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(new GelatoRecipeResource($service->save(null, $request->validated())->load('lines')), 'Recipe created', 201));
    }

    #[OA\Put(path: '/api/v1/gelato/recipes/{id}', operationId: 'updateGelatoRecipe', summary: 'Replace a recipe', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The recipe'), new OA\Response(response: 404, description: 'Unknown recipe')])]
    public function update(string $id, SaveGelatoRecipeRequest $request, GelatoRecipeService $service): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(new GelatoRecipeResource($service->save($id, $request->validated())->load('lines')), 'Recipe updated'));
    }

    #[OA\Post(path: '/api/v1/gelato/recipes/balance', operationId: 'balanceGelatoRecipe', summary: 'Balance preview or auto-balance', description: 'With lines: POD, PAC, fats, SLNG, solids, freezing curve, serving temperature, cost and the traffic-light evaluation. With targets and pool: the constrained auto-balance solver.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], responses: [new OA\Response(response: 200, description: 'The metrics or the solution'), new OA\Response(response: 422, description: 'Invalid request')])]
    public function balance(BalanceGelatoRecipeRequest $request, BalanceGelatoRecipeParametersHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new BalanceGelatoRecipeParametersCommand(
            RecipeCategory::from($data['category']),
            $data['lines'] ?? [],
            $data['pool'] ?? [],
            $data['targets'] ?? null,
            array_intersect_key($data, array_flip(['overrun_percent', 'mix_density', 'serving_portion_grams', 'tub_weight_grams'])),
            $data['recipe_id'] ?? null,
        ))));
    }

    #[OA\Post(path: '/api/v1/gelato/recipes/{id}/clone', operationId: 'cloneGelatoRecipe', summary: 'Clone a recipe', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'The copy')])]
    public function clone(string $id, GelatoRecipeActionRequest $request, GelatoRecipeService $service): JsonResponse
    {
        $code = (string) ($request->validated('code') ?? GelatoBalancedRecipe::query()->findOrFail($id)->code.'-COPY');

        return $this->guarded(fn (): JsonResponse => $this->successResponse(new GelatoRecipeResource($service->cloneRecipe($id, $code)->load('lines')), 'Recipe cloned', 201));
    }

    #[OA\Post(path: '/api/v1/gelato/recipes/{id}/scale', operationId: 'scaleGelatoRecipe', summary: 'Scale a recipe to a batch weight', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The scaled lines and cost')])]
    public function scale(string $id, GelatoRecipeActionRequest $request, GelatoRecipeService $service): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($service->scale($id, (float) $request->validated('batch_weight_grams'))));
    }

    #[OA\Post(path: '/api/v1/gelato/ganache/calculate', operationId: 'calculateGelatoGanache', summary: 'Ganache water activity and shelf life', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], responses: [new OA\Response(response: 200, description: 'a_w, water and the estimated shelf life')])]
    public function ganache(GelatoRecipeActionRequest $request, GelatoGanacheAndOrderService $service): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($service->calculate($request->validated('lines') ?? [])));
    }

    #[OA\Put(path: '/api/v1/gelato/recipes/{id}/ganache-spec', operationId: 'saveGelatoGanacheSpec', summary: 'Store the ganache spec and validate the tempering curve', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The spec')])]
    public function saveGanacheSpec(string $id, GelatoRecipeActionRequest $request, GelatoGanacheAndOrderService $service): JsonResponse
    {
        $data = $request->validated();
        $data['chocolate_type'] ??= 'dark';

        return $this->guarded(fn (): JsonResponse => $this->successResponse($service->saveSpec($id, $data), 'Ganache spec saved'));
    }
}
