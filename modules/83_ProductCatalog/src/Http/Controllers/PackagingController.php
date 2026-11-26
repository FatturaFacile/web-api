<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\ProductCatalog\Http\Controllers\Concerns\InteractsWithCatalog;
use Modules\ProductCatalog\Http\Requests\ConvertRequest;
use Modules\ProductCatalog\Http\Requests\PackagingsRequest;
use Modules\ProductCatalog\Http\Resources\ProductResource;
use Modules\ProductCatalog\Services\PackagingService;
use OpenApi\Attributes as OA;

/**
 * Multi-level units of measure: the packaging hierarchy of a product and exact conversions across its units.
 */
final class PackagingController extends Controller
{
    use ApiResponse;
    use InteractsWithCatalog;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly PackagingService $packagings) {}

    #[OA\Put(path: '/api/v1/catalog/products/{id}/packagings', operationId: 'productCatalogPackagings', summary: 'Replace the packaging hierarchy and the rounding policy of a product', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 200, description: 'Packagings saved')])]
    public function update(PackagingsRequest $request, string $id): JsonResponse
    {
        $this->authorizeCatalog();
        $product = $this->product($id);
        $product->fill($request->safe()->only(['uom_rounding', 'uom_precision']))->save();
        $this->packagings->sync($product, array_map(static fn (array $level): array => [
            'code' => (string) $level['code'],
            'uom_id' => (string) $level['uom_id'],
            'units_per_parent' => $level['units_per_parent'],
            'parent_code' => $level['parent_code'] ?? null,
            'role' => $level['role'] ?? 'logistic',
            'gtin' => $level['gtin'] ?? null,
        ], (array) $request->validated('levels')));

        return $this->successResponse(ProductResource::detail($product->refresh(), $request), $this->message('saved'));
    }

    #[OA\Post(path: '/api/v1/catalog/products/{id}/convert', operationId: 'productCatalogConvert', summary: 'Express a quantity of one unit of a product in all its units', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 200, description: 'Quantity in every unit and split into whole packages')])]
    public function convert(ConvertRequest $request, string $id): JsonResponse
    {
        $this->authorizeCatalog();

        return $this->successResponse(
            $this->packagings->describe($this->product($id), (string) $request->validated('quantity'), strtoupper((string) $request->validated('unit'))),
            $this->message('loaded'),
        );
    }
}
