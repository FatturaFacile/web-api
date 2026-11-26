<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\ProductCatalog\Http\Controllers\Concerns\InteractsWithCatalog;
use Modules\ProductCatalog\Http\Requests\MatrixRequest;
use Modules\ProductCatalog\Http\Resources\ProductResource;
use Modules\ProductCatalog\Services\VariantMatrixService;
use OpenApi\Attributes as OA;

/**
 * The multi-attribute variant matrix of a parent product: preview, generation and repricing.
 */
final class MatrixController extends Controller
{
    use ApiResponse;
    use InteractsWithCatalog;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly VariantMatrixService $matrix) {}

    #[OA\Post(path: '/api/v1/catalog/products/{id}/matrix/preview', operationId: 'productCatalogMatrixPreview', summary: 'Preview the variants a matrix would create, with SKU, price and cost', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 200, description: 'Combinations')])]
    public function preview(MatrixRequest $request, string $id): JsonResponse
    {
        $this->authorizeCatalog();
        $rows = $this->matrix->preview($this->product($id), $request->axes(), $request->overrides());

        return $this->successResponse([
            'total' => count($rows),
            'new' => count(array_filter($rows, static fn (array $row): bool => ! $row['exists'])),
            'combinations' => $rows,
        ], $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/catalog/products/{id}/matrix/generate', operationId: 'productCatalogMatrixGenerate', summary: 'Generate the missing variants of a matrix', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 201, description: 'Variants created')])]
    public function generate(MatrixRequest $request, string $id): JsonResponse
    {
        $this->authorizeCatalog();
        $result = $this->matrix->generate($this->product($id), $request->axes(), $request->overrides(), $request->boolean('generate_barcodes'));

        return $this->successResponse([
            'created' => ProductResource::collection($result['created']),
            'skipped' => $result['skipped'],
        ], $this->message('generated'), 201);
    }

    #[OA\Post(path: '/api/v1/catalog/products/{id}/matrix/reprice', operationId: 'productCatalogMatrixReprice', summary: 'Recompute the price and cost of the variants that follow their parent', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 200, description: 'Variants repriced')])]
    public function reprice(string $id): JsonResponse
    {
        $this->authorizeCatalog();

        return $this->successResponse(['repriced' => $this->matrix->reprice($this->product($id))], $this->message('saved'));
    }
}
