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
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ProductBarcodeController extends Controller
{
    use ApiResponse;

    /**
     * The barcode of a product as an SVG or PNG image (`?format=`), encoding its barcode or, when it has none, its SKU.
     * `?type=` selects ean13, ean8, code128 (default) or qrcode. The final encoded value, with the EAN check digit
     * added when it was missing, is returned in the `X-Barcode-Value` header.
     */
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
