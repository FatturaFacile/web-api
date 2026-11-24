<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Modules\Inventory\Actions\ProcessWarehouseBarcodeScanAction;
use Modules\Inventory\DTOs\BarcodeScanDTO;
use Modules\Inventory\Http\Requests\BarcodeScanRequest;
use Modules\Inventory\Http\Requests\WmsQueryRequest;
use Modules\Inventory\Services\BarcodeLookupService;
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
            new OA\Response(response: 200, description: 'Barcode resolved and parsed', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'type', type: 'string', description: 'Kind of record the code resolved to: bin, serial_number, lot, item, gs1_composite or unknown (nothing matched)'),
                new OA\Property(property: 'entity', type: 'object', description: 'The matched record as stored (bin, serial number, lot or stock item)'),
                new OA\Property(property: 'data', type: 'object', description: 'Key fields of the record: ids, code, stock item, status or expiry depending on type'),
            ])),
            new OA\Response(response: 422, description: 'Unrecognized barcode'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Parse and resolve warehouse barcode', tags: ['Inventory'])]
    public function scan(BarcodeScanRequest $request, ProcessWarehouseBarcodeScanAction $action): JsonResponse
    {
        $dto = new BarcodeScanDTO(
            rawBarcode: $request->string('raw_payload')->value(),
            warehouseId: $request->input('warehouse_id')
        );

        $result = $action->execute($dto);

        return response()->json($result);
    }

    #[OA\Get(
        path: '/api/v1/inventory/barcode/lookup',
        operationId: 'wmsBarcodeLookup',
        summary: 'Look up a scanned code (handheld)',
        description: 'Read-only resolution of a scanned code for handhelds: GS1-128 strings (AI 01 GTIN, 10 lot, 17 expiry, 21 serial; with parentheses or FNC1/GS separators, optional ]C1 prefix) are decoded first; plain codes are tried as GTIN-8/12/13/14 (on the stock item or its catalog product barcode), SKU/internal barcode, bin barcode, lot or supplier lot number and serial number. Every match is returned with its stock (on hand, reserved, available per bin; optionally of one warehouse). An unknown code returns an empty matches list.',
        tags: ['Inventory Barcode Scanner'],
        parameters: [
            new OA\Parameter(name: 'code', in: 'query', required: true, schema: new OA\Schema(type: 'string', example: '(01)04006381333931(10)L-2026-01(17)271231')),
            new OA\Parameter(ref: '#/components/parameters/WmsWarehouseFilter'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Matches of the code', content: new OA\JsonContent(ref: '#/components/schemas/WmsBarcodeLookup')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function lookup(WmsQueryRequest $request, BarcodeLookupService $lookup): JsonResponse
    {
        return response()->json($lookup->lookup($request->string('code')->value(), $request->input('warehouse_id')));
    }
}
