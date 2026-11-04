<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Exceptions\BarcodeGenerationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ProductBarcodeRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Services\Inventory\BarcodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ProductBarcodeController extends Controller
{
    use ApiResponse;

    /**
     * The barcode of a product as an SVG or PNG image (`?format=`), encoding its barcode or, when it has none, its SKU.
     * `?type=` selects ean13, ean8, code128 (default) or qrcode. The final encoded value, with the EAN check digit
     * added when it was missing, is returned in the `X-Barcode-Value` header.
     */
    #[OA\Get(
        path: '/api/v1/inventory/products/{id}/barcode',
        operationId: 'getProductBarcodeImage',
        description: 'Generate product barcode image stream in SVG or PNG format (supports EAN-13, EAN-8, Code 128, and QR).',
        summary: 'Get product barcode image',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Inventory', 'Barcodes'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['ean13', 'ean8', 'code128', 'qrcode'])),
            new OA\Parameter(name: 'format', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['svg', 'png'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Barcode image stream', content: new OA\MediaType(mediaType: 'image/svg+xml')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Product not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Barcode generation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(ProductBarcodeRequest $request, BarcodeService $barcodes, string $id): Response|JsonResponse
    {
        $product = Product::findOrFail($id);
        $type = $request->barcodeType();
        $format = $request->outputFormat();

        try {
            $value = $barcodes->normalize($type, (string) ($product->barcode ?: $product->sku));
            $image = $barcodes->render($type, $value, $format, $request->scale(), $request->height());
        } catch (BarcodeGenerationException $e) {
            return $this->problemDetailsResponse(
                title: __('labels.problem_titles.barcode'),
                detail: $e->getMessage(),
                status: HttpResponse::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return response($image, HttpResponse::HTTP_OK, [
            'Content-Type' => $format === BarcodeService::FORMAT_SVG ? 'image/svg+xml; charset=UTF-8' : 'image/png',
            'X-Barcode-Value' => $value,
            'X-Barcode-Type' => $type->value,
        ]);
    }
}
