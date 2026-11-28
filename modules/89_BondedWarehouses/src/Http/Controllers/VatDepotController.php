<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\BondedWarehouses\Application\Commands\ExecuteVatDepotExtractionAndEmitSelfInvoiceCommand;
use Modules\BondedWarehouses\Application\Commands\RegisterVatDepot50BisInboundCommand;
use Modules\BondedWarehouses\Application\Handlers\ExecuteVatDepotExtractionAndEmitSelfInvoiceHandler;
use Modules\BondedWarehouses\Application\Handlers\RegisterVatDepot50BisInboundHandler;
use Modules\BondedWarehouses\Application\Queries\RegistryQueries;
use Modules\BondedWarehouses\Http\Controllers\Concerns\InteractsWithBondedWarehouses;
use Modules\BondedWarehouses\Http\Requests\RegisterVatDepotInboundRequest;
use Modules\BondedWarehouses\Http\Requests\VatDepotExtractionRequest;
use Modules\BondedWarehouses\Http\Resources\VatRegisterResource;
use Modules\BondedWarehouses\Models\VatDepotRegisterEntry;
use OpenApi\Attributes as OA;

/**
 * Telematic load / unload register of the IVA warehouses (Art. 50-bis DL 331/93) and the extraction with its TD22 / TD23 self-invoice.
 */
final class VatDepotController extends Controller
{
    use ApiResponse;
    use InteractsWithBondedWarehouses;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/bonded-warehouses/vat-register', operationId: 'bondedWarehousesVatRegisterIndex', summary: 'Art. 50-bis load / unload register in progressive order', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 200, description: 'Register rows')])]
    public function index(RegistryQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', VatDepotRegisterEntry::class);

        return $this->paged($queries->vatRegister($this->filters(), $this->perPage()), VatRegisterResource::class);
    }

    #[OA\Post(path: '/api/v1/bonded-warehouses/vat-register/inbound', operationId: 'bondedWarehousesVatRegisterInbound', summary: 'Register goods loaded into an IVA warehouse', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 201, description: 'Register row')])]
    public function inbound(RegisterVatDepotInboundRequest $request, RegisterVatDepot50BisInboundHandler $handler): JsonResponse
    {
        Gate::authorize('create', VatDepotRegisterEntry::class);

        return $this->createdResponse(new VatRegisterResource($handler->handle(RegisterVatDepot50BisInboundCommand::fromArray($request->validated(), $this->userId()))), $this->message('vat_inbound_registered'));
    }

    #[OA\Post(path: '/api/v1/bonded-warehouses/vat-register/extraction/preview', operationId: 'bondedWarehousesVatExtractionPreview', summary: 'Taxable base, VAT and document type of an extraction (nothing is written)', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 200, description: 'Extraction preview')])]
    public function preview(VatDepotExtractionRequest $request, ExecuteVatDepotExtractionAndEmitSelfInvoiceHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', VatDepotRegisterEntry::class);

        return $this->successResponse($handler->preview(ExecuteVatDepotExtractionAndEmitSelfInvoiceCommand::fromArray($request->validated(), $this->userId())), $this->message('extraction_previewed'));
    }

    #[OA\Post(path: '/api/v1/bonded-warehouses/vat-register/extraction', operationId: 'bondedWarehousesVatExtraction', summary: 'Extract goods from an IVA warehouse and emit the TD22 / TD23 self-invoice', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 201, description: 'Register row with the self-invoice')])]
    public function extract(VatDepotExtractionRequest $request, ExecuteVatDepotExtractionAndEmitSelfInvoiceHandler $handler): JsonResponse
    {
        Gate::authorize('create', VatDepotRegisterEntry::class);

        return $this->createdResponse(new VatRegisterResource($handler->handle(ExecuteVatDepotExtractionAndEmitSelfInvoiceCommand::fromArray($request->validated(), $this->userId()))), $this->message('extraction_emitted'));
    }
}
