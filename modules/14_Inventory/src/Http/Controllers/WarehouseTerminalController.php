<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Modules\Inventory\Http\Requests\TerminalScanRequest;
use Modules\Inventory\Services\WarehouseTerminalService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Terminal', description: 'Handheld barcode terminal operations; a scan sent twice is applied once')]
final class WarehouseTerminalController extends Controller
{
    public function __construct(
        private readonly WarehouseTerminalService $terminal,
        private readonly TenantContext $tenantContext
    ) {}

    #[OA\Post(
        path: '/api/v1/inventory/terminal/receipt',
        summary: 'Scan a goods receipt',
        description: 'Books goods into the scanned bin, creating the lot when new. GS1-128 codes bring lot and expiry with them.',
        tags: ['Inventory Terminal'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['device_id', 'client_scan_id', 'item_code', 'bin_code', 'quantity'],
            properties: [
                new OA\Property(property: 'device_id', type: 'string', example: 'TERM-07'),
                new OA\Property(property: 'client_scan_id', type: 'string', description: 'Unique per device; a resend with the same id replays the first answer'),
                new OA\Property(property: 'item_code', type: 'string', example: '(01)08001234567890(10)LOT7(17)270630'),
                new OA\Property(property: 'bin_code', type: 'string'),
                new OA\Property(property: 'quantity', type: 'number', example: 24),
                new OA\Property(property: 'lot_number', type: 'string'),
                new OA\Property(property: 'expiration_date', type: 'string', format: 'date'),
                new OA\Property(property: 'serial_number', type: 'string'),
                new OA\Property(property: 'purchase_receipt_id', type: 'string', format: 'uuid'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Scan applied', content: new OA\JsonContent(ref: '#/components/schemas/InventoryTerminalResult')),
            new OA\Response(response: 422, description: 'Unknown code or refused by a stock rule'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Scan a goods receipt', tags: ['Inventory'])]
    public function receipt(TerminalScanRequest $request): JsonResponse
    {
        return response()->json($this->terminal->receive($this->tenantId(), (string) auth()->id(), $request->validated()));
    }

    #[OA\Post(
        path: '/api/v1/inventory/terminal/pick',
        summary: 'Scan a pick',
        description: 'Confirms a stop of a pick list after checking that bin, item and lot are the ones expected.',
        tags: ['Inventory Terminal'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['device_id', 'client_scan_id', 'pick_line_id', 'item_code', 'bin_code', 'quantity'],
            properties: [
                new OA\Property(property: 'device_id', type: 'string'),
                new OA\Property(property: 'client_scan_id', type: 'string'),
                new OA\Property(property: 'pick_line_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'item_code', type: 'string'),
                new OA\Property(property: 'bin_code', type: 'string'),
                new OA\Property(property: 'quantity', type: 'number'),
                new OA\Property(property: 'lot_number', type: 'string'),
                new OA\Property(property: 'destination_bin_code', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Scan applied', content: new OA\JsonContent(ref: '#/components/schemas/InventoryTerminalResult')),
            new OA\Response(response: 422, description: 'Wrong bin, item or lot, or the line is closed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Scan a pick', tags: ['Inventory'])]
    public function pick(TerminalScanRequest $request): JsonResponse
    {
        return response()->json($this->terminal->pick($this->tenantId(), (string) auth()->id(), $request->validated()));
    }

    #[OA\Post(
        path: '/api/v1/inventory/terminal/transfer',
        summary: 'Scan a transfer',
        description: 'Moves stock between two scanned bins, inside a warehouse or towards a transit area.',
        tags: ['Inventory Terminal'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['device_id', 'client_scan_id', 'item_code', 'from_bin_code', 'to_bin_code', 'quantity'],
            properties: [
                new OA\Property(property: 'device_id', type: 'string'),
                new OA\Property(property: 'client_scan_id', type: 'string'),
                new OA\Property(property: 'item_code', type: 'string'),
                new OA\Property(property: 'from_bin_code', type: 'string'),
                new OA\Property(property: 'to_bin_code', type: 'string'),
                new OA\Property(property: 'quantity', type: 'number'),
                new OA\Property(property: 'lot_number', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Scan applied', content: new OA\JsonContent(ref: '#/components/schemas/InventoryTerminalResult')),
            new OA\Response(response: 422, description: 'Unknown code or not enough stock'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Scan a transfer', tags: ['Inventory'])]
    public function transfer(TerminalScanRequest $request): JsonResponse
    {
        return response()->json($this->terminal->transfer($this->tenantId(), (string) auth()->id(), $request->validated()));
    }

    #[OA\Post(
        path: '/api/v1/inventory/terminal/count',
        summary: 'Scan a stocktake count',
        description: 'Records a counted quantity on an open count session, adding a line for goods found where none were expected.',
        tags: ['Inventory Terminal'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['device_id', 'client_scan_id', 'session_id', 'item_code', 'bin_code', 'quantity'],
            properties: [
                new OA\Property(property: 'device_id', type: 'string'),
                new OA\Property(property: 'client_scan_id', type: 'string'),
                new OA\Property(property: 'session_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'item_code', type: 'string'),
                new OA\Property(property: 'bin_code', type: 'string'),
                new OA\Property(property: 'quantity', type: 'number'),
                new OA\Property(property: 'lot_number', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Scan applied', content: new OA\JsonContent(ref: '#/components/schemas/InventoryTerminalResult')),
            new OA\Response(response: 422, description: 'Unknown code or closed session'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Scan a stocktake count', tags: ['Inventory'])]
    public function count(TerminalScanRequest $request): JsonResponse
    {
        return response()->json($this->terminal->count($this->tenantId(), (string) auth()->id(), $request->validated()));
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }
}
