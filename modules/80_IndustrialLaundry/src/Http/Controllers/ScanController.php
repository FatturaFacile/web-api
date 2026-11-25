<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\IndustrialLaundry\Application\Commands\ProcessRfidTunnelGateScanCommand;
use Modules\IndustrialLaundry\Application\Commands\ProcessRfidTunnelGateScanHandler;
use Modules\IndustrialLaundry\Application\Queries\GetEntityDetailHandler;
use Modules\IndustrialLaundry\Application\Queries\GetEntityDetailQuery;
use Modules\IndustrialLaundry\Application\Queries\GetScanSessionsHandler;
use Modules\IndustrialLaundry\Application\Queries\GetScanSessionsQuery;
use Modules\IndustrialLaundry\Http\Controllers\Concerns\InteractsWithLaundry;
use Modules\IndustrialLaundry\Http\Requests\ListFiltersRequest;
use Modules\IndustrialLaundry\Http\Requests\ScanRequest;
use Modules\IndustrialLaundry\Http\Resources\ScanSessionResource;
use Modules\IndustrialLaundry\Models\ScanSession;
use OpenApi\Attributes as OA;

/**
 * Tunnel gate scans: the endpoint the UHF reader middleware (WPF LLRP edge, Flutter handheld, web console) posts a roll cage to.
 */
final class ScanController extends Controller
{
    use ApiResponse;
    use InteractsWithLaundry;

    #[OA\Post(path: '/api/v1/industrial-laundry/scans', operationId: 'industrialLaundryProcessScan', summary: 'Process a roll cage read at a tunnel gate: filter the reads, weigh, move the garments and draw up the DDT', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], responses: [new OA\Response(response: 201, description: 'Scan session processed')])]
    public function store(ScanRequest $request, ProcessRfidTunnelGateScanHandler $handler): JsonResponse
    {
        Gate::authorize('create', ScanSession::class);

        $data = $request->validated();
        $session = $handler->handle(new ProcessRfidTunnelGateScanCommand(
            tenantId: $this->tenantId(),
            userId: $this->userId(),
            gateAntennaId: (string) $data['gate_antenna_id'],
            rollCageBarcode: (string) $data['roll_cage_barcode'],
            direction: (string) $data['direction'],
            reads: array_values($data['reads']),
            grossWeightKg: isset($data['gross_weight_kg']) ? (string) $data['gross_weight_kg'] : null,
            rollCageTareWeightKg: isset($data['roll_cage_tare_weight_kg']) ? (string) $data['roll_cage_tare_weight_kg'] : null,
            contactId: $data['contact_id'] ?? null,
            externalScanId: $data['external_scan_id'] ?? null,
            scannedAt: $data['scanned_at'] ?? null,
            generateDdt: (bool) ($data['generate_ddt'] ?? true),
        ));

        return $this->successResponse(new ScanSessionResource($session->load('customer')), $this->message('scanned'), 201);
    }

    #[OA\Get(path: '/api/v1/industrial-laundry/scans', operationId: 'industrialLaundryListScans', summary: 'List the scan sessions of the tunnel gates', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], responses: [new OA\Response(response: 200, description: 'Scan sessions, paginated')])]
    public function index(ListFiltersRequest $request, GetScanSessionsHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', ScanSession::class);

        return $this->paged($handler->handle(new GetScanSessionsQuery(
            direction: $request->input('direction'),
            contactId: $request->input('contact_id'),
            from: $request->input('from'),
            to: $request->input('to'),
            rollCageBarcode: $request->input('barcode'),
            perPage: $this->perPage(),
        )), ScanSessionResource::class);
    }

    #[OA\Get(path: '/api/v1/industrial-laundry/scans/{id}', operationId: 'industrialLaundryShowScan', summary: 'Read a scan session with its reads, rejections and alerts', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Scan session')])]
    public function show(string $id, GetEntityDetailHandler $handler): JsonResponse
    {
        $session = $handler->handle(new GetEntityDetailQuery(GetEntityDetailQuery::ScanSession, $id));
        Gate::authorize('view', $session);

        return $this->successResponse(new ScanSessionResource($session), $this->message('listed'));
    }
}
