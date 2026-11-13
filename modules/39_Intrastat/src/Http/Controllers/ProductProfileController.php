<?php

declare(strict_types=1);

namespace Modules\Intrastat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Intrastat\Http\Requests\ProductProfileRequest;
use Modules\Intrastat\Http\Resources\ProductProfileResource;
use Modules\Intrastat\Models\IntrastatProductProfile;
use OpenApi\Attributes as OA;

/**
 * The Intrastat data of the products, which the lists are drafted from.
 */
final class ProductProfileController extends Controller
{
    #[OA\Get(path: '/api/v1/intrastat/product-profiles', operationId: 'listIntrastatProductProfiles', description: 'Return the Intrastat data of the products of the company.', summary: 'List Intrastat product data', security: [['bearerAuth' => []]], tags: ['Intrastat'], responses: [
        new OA\Response(response: 200, description: 'Intrastat product data', content: new OA\JsonContent(ref: '#/components/schemas/IntrastatProductProfileCollection')),
        new OA\Response(ref: '#/components/responses/IntrastatUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/IntrastatForbidden', response: 403),
    ])]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', IntrastatProductProfile::class);

        return ProductProfileResource::collection(
            IntrastatProductProfile::query()->with('product')->orderBy('created_at')->gridPaginate(RequestedPerPage::resolve(25))
        );
    }

    #[OA\Put(path: '/api/v1/intrastat/product-profiles/{productId}', operationId: 'upsertIntrastatProductProfile', description: 'Set the Intrastat data of a product: good or service, combined nomenclature code (8 digits) or CPA service code (6 digits), net mass in kg and supplementary units per unit sold, country and province of origin.', summary: 'Set Intrastat product data', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/IntrastatProductProfileRequest')), tags: ['Intrastat'], parameters: [new OA\Parameter(ref: '#/components/parameters/IntrastatProductId')], responses: [
        new OA\Response(response: 200, description: 'Intrastat product data saved', content: new OA\JsonContent(ref: '#/components/schemas/IntrastatProductProfileResponse')),
        new OA\Response(ref: '#/components/responses/IntrastatUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/IntrastatForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/IntrastatNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/IntrastatUnprocessable', response: 422),
    ])]
    public function upsert(ProductProfileRequest $request, string $productId): JsonResponse
    {
        $product = Product::query()->whereKey($productId)->firstOrFail();
        $profile = IntrastatProductProfile::query()->firstOrNew(['product_id' => $product->id]);

        $this->authorize($profile->exists ? 'update' : 'create', $profile->exists ? $profile : IntrastatProductProfile::class);

        $profile->fill($request->validated())->save();

        return (new ProductProfileResource($profile->load('product')))->response()->setStatusCode(Response::HTTP_OK);
    }

    #[OA\Delete(path: '/api/v1/intrastat/product-profiles/{productId}', operationId: 'deleteIntrastatProductProfile', description: 'Remove the Intrastat data of a product.', summary: 'Delete Intrastat product data', security: [['bearerAuth' => []]], tags: ['Intrastat'], parameters: [new OA\Parameter(ref: '#/components/parameters/IntrastatProductId')], responses: [
        new OA\Response(response: 204, description: 'Intrastat product data removed'),
        new OA\Response(ref: '#/components/responses/IntrastatUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/IntrastatForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/IntrastatNotFound', response: 404),
    ])]
    public function destroy(string $productId): Response
    {
        $profile = IntrastatProductProfile::query()->where('product_id', $productId)->firstOrFail();
        $this->authorize('delete', $profile);
        $profile->delete();

        return response()->noContent();
    }
}
