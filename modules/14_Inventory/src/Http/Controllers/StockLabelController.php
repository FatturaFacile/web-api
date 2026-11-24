<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Enums\BarcodeType;
use App\Exceptions\BarcodeGenerationException;
use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Inventory\Services\StockLabelService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Labels', description: 'Product label generation and printing presets')]
final class StockLabelController extends Controller
{
    #[OA\Post(
        path: '/api/v1/inventory/labels/barcode',
        summary: 'Render a barcode label',
        description: 'The EAN-13 or Code 39 barcode of an item, lot, serial, bin or package, as SVG markup or base64 PNG. Product sheets keep using `/inventory/labels/print`.',
        tags: ['Inventory Labels'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['target', 'id', 'symbology'],
            properties: [
                new OA\Property(property: 'target', type: 'string', enum: ['item', 'lot', 'serial', 'bin', 'package']),
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'symbology', type: 'string', enum: ['ean13', 'code39']),
                new OA\Property(property: 'format', type: 'string', default: 'svg', enum: ['svg', 'png']),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Barcode rendered', content: new OA\JsonContent(ref: '#/components/schemas/InventoryBarcodeLabel')),
            new OA\Response(response: 404, description: 'Record not found'),
            new OA\Response(response: 422, description: 'The value does not fit the symbology'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Render a barcode label', tags: ['Inventory'])]
    public function barcode(Request $request, StockLabelService $labels): JsonResponse
    {
        $validated = $request->validate([
            'target' => ['required', 'string', 'in:item,lot,serial,bin,package'],
            'id' => ['required', 'uuid'],
            'symbology' => ['required', 'string', Rule::in([BarcodeType::Ean13->value, BarcodeType::Code39->value])],
            'format' => ['nullable', 'string', 'in:svg,png'],
        ]);

        try {
            return response()->json($labels->render($validated['target'], $validated['id'], BarcodeType::from($validated['symbology']), $validated['format'] ?? 'svg'));
        } catch (BarcodeGenerationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
