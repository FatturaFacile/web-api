<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ArtisanalGelato\Domain\Enums\RecipeCategory;
use Modules\ArtisanalGelato\Domain\Services\AllergenCatalog;
use Modules\ArtisanalGelato\Domain\Services\TemperingCurveCatalog;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoTechnicalIngredient;
use Modules\ArtisanalGelato\Presentation\Http\Controllers\Concerns\MapsGelatoErrors;
use Modules\ArtisanalGelato\Presentation\Http\Requests\StoreGelatoIngredientRequest;
use Modules\ArtisanalGelato\Presentation\Http\Resources\GelatoIngredientResource;
use OpenApi\Attributes as OA;

/**
 * The technical ingredient catalog (the global seeded catalog plus the ingredients of the tenant) and the reference data the
 * balancing workbench needs (categories and their bands, allergens, tempering curves).
 */
final class GelatoCatalogController extends Controller
{
    use ApiResponse;
    use MapsGelatoErrors;

    #[OA\Get(path: '/api/v1/gelato/ingredients', operationId: 'listGelatoIngredients', summary: 'Technical ingredient catalog', description: 'Filter by category (category) and search by code or name (q); at most 500 rows.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], responses: [new OA\Response(response: 200, description: 'The ingredients')])]
    public function index(Request $request): JsonResponse
    {
        $query = GelatoTechnicalIngredient::query()->where('is_active', true)->orderBy('category')->orderBy('code');

        if ($request->filled('category')) {
            $query->where('category', (string) $request->query('category'));
        }

        if ($request->filled('q')) {
            $needle = '%'.mb_strtolower((string) $request->query('q')).'%';
            $query->where(static fn ($q) => $q->whereRaw('LOWER(code) LIKE ?', [$needle])->orWhereRaw('LOWER(name) LIKE ?', [$needle]));
        }

        return $this->successResponse(GelatoIngredientResource::collection($query->limit(500)->get()));
    }

    #[OA\Post(path: '/api/v1/gelato/ingredients', operationId: 'createGelatoIngredient', summary: 'Create a tenant ingredient', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], responses: [new OA\Response(response: 201, description: 'The ingredient'), new OA\Response(response: 422, description: 'Invalid ingredient')])]
    public function store(StoreGelatoIngredientRequest $request): JsonResponse
    {
        return $this->successResponse(new GelatoIngredientResource(GelatoTechnicalIngredient::query()->create($this->attributes($request->validated()))), 'Ingredient created', 201);
    }

    #[OA\Put(path: '/api/v1/gelato/ingredients/{id}', operationId: 'updateGelatoIngredient', summary: 'Update a tenant ingredient', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The ingredient'), new OA\Response(response: 404, description: 'Unknown ingredient')])]
    public function update(string $id, StoreGelatoIngredientRequest $request): JsonResponse
    {
        $ingredient = GelatoTechnicalIngredient::query()->findOrFail($id);
        $ingredient->fill($this->attributes($request->validated()))->save();

        return $this->successResponse(new GelatoIngredientResource($ingredient), 'Ingredient updated');
    }

    #[OA\Get(path: '/api/v1/gelato/reference', operationId: 'getGelatoReference', summary: 'Balancing reference data', description: 'Recipe categories with their optimal bands, the 14 allergens in IT/DE/FR/EN and the tempering curves.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], responses: [new OA\Response(response: 200, description: 'The reference data')])]
    public function reference(TemperingCurveCatalog $tempering): JsonResponse
    {
        $categories = [];

        foreach (RecipeCategory::cases() as $category) {
            $categories[] = ['code' => $category->value, 'bands' => $category->bands(), 'frozen' => $category->isFrozen()];
        }

        $allergens = [];

        foreach (['it', 'de', 'fr', 'en'] as $locale) {
            $allergens[$locale] = AllergenCatalog::names($locale);
        }

        return $this->successResponse([
            'categories' => $categories,
            'allergens' => $allergens,
            'tempering_curves' => array_combine($tempering->types(), array_map($tempering->curve(...), $tempering->types())),
            'curve_temperatures' => [-10, -11, -12, -13, -14, -15, -16, -17, -18],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $data['allergens'] ??= [];
        $data['solute_class'] ??= 'none';
        $data['water_percent'] ??= max(0, 100 - (float) ($data['total_solids_percent'] ?? 0));

        return $data;
    }
}
