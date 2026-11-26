<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\ProductCatalog\Http\Controllers\Concerns\InteractsWithCatalog;
use Modules\ProductCatalog\Http\Requests\KitComponentsRequest;
use Modules\ProductCatalog\Http\Requests\KitExplodeRequest;
use Modules\ProductCatalog\Http\Resources\ProductResource;
use Modules\ProductCatalog\Services\KitService;
use OpenApi\Attributes as OA;

/**
 * Kits and bundles: the bill of materials of a kit and the explosion of commercial lines into picking and transport lines.
 */
final class KitController extends Controller
{
    use ApiResponse;
    use InteractsWithCatalog;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly KitService $kits) {}

    #[OA\Put(path: '/api/v1/catalog/products/{id}/kit-components', operationId: 'productCatalogKitComponents', summary: 'Replace the bill of materials of a kit', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 200, description: 'Kit saved')])]
    public function update(KitComponentsRequest $request, string $id): JsonResponse
    {
        $this->authorizeCatalog();
        $kit = $this->product($id);
        $this->kits->setComponents($kit, array_map(static fn (array $line): array => [
            'component_id' => (string) $line['component_id'],
            'quantity' => $line['quantity'],
            'is_optional' => (bool) ($line['is_optional'] ?? false),
        ], (array) $request->validated('components')));

        return $this->successResponse(ProductResource::detail($kit->refresh(), $request), $this->message('saved'));
    }

    #[OA\Get(path: '/api/v1/catalog/products/{id}/explosion', operationId: 'productCatalogKitExplosion', summary: 'The components a quantity of a product resolves to', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 200, description: 'Exploded lines')])]
    public function explosion(string $id): JsonResponse
    {
        $this->authorizeCatalog();
        $raw = request()->query('quantity', '1');
        $quantity = is_string($raw) && preg_match('/^\d+(\.\d{1,6})?$/', $raw) === 1 && (float) $raw > 0 ? $raw : '1';

        return $this->successResponse($this->kits->explode($this->product($id), $quantity, request()->boolean('include_optional')), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/catalog/kits/explode', operationId: 'productCatalogKitExplodeLines', summary: 'Split commercial lines from picking and transport document lines', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 200, description: 'Commercial and warehouse lines')])]
    public function explodeLines(KitExplodeRequest $request): JsonResponse
    {
        $this->authorizeCatalog();

        return $this->successResponse($this->kits->explodeLines(array_map(static fn (array $line): array => [
            'product_id' => (string) $line['product_id'],
            'quantity' => (string) $line['quantity'],
            'include_optional' => (bool) ($line['include_optional'] ?? false),
        ], (array) $request->validated('lines'))), $this->message('loaded'));
    }
}
