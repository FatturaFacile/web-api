<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Modules\Inventory\Actions\ProcessWarehouseBarcodeScanAction;
use Modules\Inventory\DTOs\BarcodeScanDTO;
use Modules\Inventory\Http\Requests\BarcodeScanRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Barcode Scanner', description: 'Barcode processing for GS1-128, EAN-13, serial, lot, and bin identifiers')]
final class BarcodeController extends Controller
{
    #[OA\Post(
        path: '/api/v1/inventory/barcode/scan',
        summary: 'Parse and resolve warehouse barcode',
        description: 'Decodes raw barcode scan data (GS1-128 AIs, EAN-13, custom serial/lot) and resolves associated items or bins.',
        tags: ['Inventory Barcode Scanner'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['raw_payload'],
                properties: [
                    new OA\Property(property: 'raw_payload', type: 'string', example: '(01)08001234567890(10)LOT-2026-X1'),
                    new OA\Property(property: 'symbology', type: 'string', example: 'auto', enum: ['auto', 'GS1-128', 'EAN-13', 'CODE-128', 'QR', 'DataMatrix']),
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Barcode resolved and parsed'),
            new OA\Response(response: 422, description: 'Unrecognized barcode'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Parse and resolve warehouse barcode', tags: ['Inventory'])]
    public function scan(BarcodeScanRequest $request, ProcessWarehouseBarcodeScanAction $action): JsonResponse
    {
        $dto = new BarcodeScanDTO(
            rawPayload: $request->string('raw_payload')->value(),
            symbology: $request->string('symbology', 'auto')->value(),
            warehouseId: $request->input('warehouse_id')
        );

        $result = $action->execute($dto);

        return response()->json($result);
    }
}
