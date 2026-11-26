<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CustomsTaricCode;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\UnitOfMeasure;
use App\Models\VatCode;
use Illuminate\Http\JsonResponse;
use Modules\ProductCatalog\Domain\Enums\DeltaType;
use Modules\ProductCatalog\Domain\Enums\ImportMode;
use Modules\ProductCatalog\Domain\Enums\KitType;
use Modules\ProductCatalog\Domain\Enums\PackagingRole;
use Modules\ProductCatalog\Domain\Enums\ProductType;
use Modules\ProductCatalog\Domain\Enums\RoundingMode;
use Modules\ProductCatalog\Http\Controllers\Concerns\InteractsWithCatalog;
use OpenApi\Attributes as OA;

/**
 * Reference data of the catalog screens: the codes the API reports (their texts come from the frontend dictionary), the units of
 * measure, the Swiss and Italian VAT codes, the categories and brands, and the customs code search.
 */
final class LookupController extends Controller
{
    use ApiResponse;
    use InteractsWithCatalog;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/catalog/lookups', operationId: 'productCatalogLookups', summary: 'Codes, units of measure, VAT codes, categories and brands of the catalog', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 200, description: 'Lookups')])]
    public function lookups(): JsonResponse
    {
        $this->authorizeCatalog();

        return $this->successResponse([
            'product_types' => ProductType::values(),
            'kit_types' => KitType::values(),
            'packaging_roles' => PackagingRole::values(),
            'rounding_modes' => RoundingMode::values(),
            'delta_types' => DeltaType::values(),
            'import_modes' => ImportMode::values(),
            'units' => UnitOfMeasure::query()->orderBy('code')->get(['id', 'code', 'name'])->map(static fn (UnitOfMeasure $unit): array => ['id' => $unit->id, 'code' => $unit->code, 'name' => $unit->name])->all(),
            'vat_codes' => VatCode::query()->whereIn('country_iso', ['IT', 'CH'])->where('is_active', true)->orderBy('country_iso')->orderBy('code')->get()
                ->map(static fn (VatCode $code): array => ['id' => $code->getKey(), 'code' => $code->getAttribute('code'), 'country' => $code->getAttribute('country_iso'), 'rate' => $code->getAttribute('rate')])->all(),
            'categories' => ProductCategory::query()->orderBy('name')->get(['id', 'name'])->map(static fn (ProductCategory $category): array => ['id' => $category->id, 'name' => $category->name])->all(),
            'brands' => ProductBrand::query()->orderBy('name')->get(['id', 'name'])->map(static fn (ProductBrand $brand): array => ['id' => $brand->id, 'name' => $brand->name])->all(),
        ], $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/catalog/customs-codes', operationId: 'productCatalogCustomsCodes', summary: 'Search the TARIC / HS code catalog', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 200, description: 'Customs codes')])]
    public function customsCodes(): JsonResponse
    {
        $this->authorizeCatalog();
        $needle = '%'.mb_strtolower((string) request()->query('q', '')).'%';
        $locale = app()->getLocale();

        $codes = CustomsTaricCode::query()->where('is_active', true)
            ->where(static fn ($query) => $query->whereRaw('lower(code) like ?', [$needle])->orWhereRaw('lower(cast(description as text)) like ?', [$needle]))
            ->orderBy('code')->limit(50)->get()
            ->map(static function (CustomsTaricCode $code) use ($locale): array {
                $texts = (array) $code->getAttribute('description');

                return [
                    'id' => $code->getKey(),
                    'code' => $code->getAttribute('code'),
                    'level' => $code->level->value,
                    'description' => (string) ($texts[$locale] ?? $texts['en'] ?? ''),
                ];
            })->all();

        return $this->successResponse($codes, $this->message('loaded'));
    }
}
