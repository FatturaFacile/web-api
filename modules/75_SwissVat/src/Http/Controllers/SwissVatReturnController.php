<?php

declare(strict_types=1);

namespace Modules\SwissVat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Modules\SwissVat\Application\Commands\CalculateSwissVatReturnCommand;
use Modules\SwissVat\Application\Commands\CalculateSwissVatReturnHandler;
use Modules\SwissVat\Application\Commands\ExportSuisseTaxXmlCommand;
use Modules\SwissVat\Application\Commands\ExportSuisseTaxXmlHandler;
use Modules\SwissVat\Application\Commands\FinalizeSwissVatReturnCommand;
use Modules\SwissVat\Application\Commands\FinalizeSwissVatReturnHandler;
use Modules\SwissVat\Application\Queries\GetSwissVatReconciliationHandler;
use Modules\SwissVat\Application\Queries\GetSwissVatReconciliationQuery;
use Modules\SwissVat\Http\Controllers\Concerns\ResolvesSwissVatContext;
use Modules\SwissVat\Http\Requests\CalculateSwissVatReturnRequest;
use Modules\SwissVat\Http\Requests\EmptySwissVatActionRequest;
use Modules\SwissVat\Http\Resources\SwissVatReturnResource;
use Modules\SwissVat\Http\Resources\SwissVatXmlExportResource;
use Modules\SwissVat\Services\SwissVatConfigService;
use Modules\SwissVat\Services\SwissVatReturnWorkflowService;
use OpenApi\Attributes as OA;

/**
 * Swiss VAT returns: calculation (Effective & Saldosteuersatz), AFC reconciliation & quadratura,
 * finalization, XSD validation, and SuisseTax XML export/download.
 */
final class SwissVatReturnController extends Controller
{
    use ApiResponse;
    use ResolvesSwissVatContext;

    public function __construct(
        private readonly SwissVatReturnWorkflowService $workflow,
        private readonly SwissVatConfigService $configService,
        private readonly CalculateSwissVatReturnHandler $calculateHandler,
        private readonly FinalizeSwissVatReturnHandler $finalizeHandler,
        private readonly ExportSuisseTaxXmlHandler $exportHandler,
        private readonly GetSwissVatReconciliationHandler $reconciliationHandler,
    ) {}

    #[OA\Get(path: '/api/v1/swiss-vat/returns', operationId: 'listSwissVatReturns', summary: 'List Swiss VAT periodic returns', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], responses: [new OA\Response(response: 200, description: 'Swiss VAT returns')])]
    public function index(Request $request): JsonResponse
    {
        $year = $request->query('year') !== null ? (int) $request->query('year') : null;

        return $this->successResponse(SwissVatReturnResource::collection($this->workflow->list($this->tenantId(), $year)));
    }

    #[OA\Post(path: '/api/v1/swiss-vat/returns/calculate', operationId: 'calculateSwissVatReturn', summary: 'Calculate or recalculate a Swiss VAT period return', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], responses: [new OA\Response(response: 200, description: 'Return calculated'), new OA\Response(response: 409, description: 'Return is already finalized')])]
    public function calculate(CalculateSwissVatReturnRequest $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $config = $this->configService->getOrDefault($tenantId);
        $validated = $request->validated();

        $vatReturn = $this->calculateHandler->handle(new CalculateSwissVatReturnCommand(
            tenantId: $tenantId,
            actorUserId: $this->userId($request),
            fiscalYear: (int) $validated['fiscal_year'],
            periodType: (string) ($validated['period_type'] ?? $config->reporting_frequency),
            periodNumber: (int) $validated['period_number'],
            overrides: $validated,
        ));

        return $this->successResponse(new SwissVatReturnResource($vatReturn), __('swiss_vat.messages.return_calculated'));
    }

    #[OA\Get(path: '/api/v1/swiss-vat/returns/{id}', operationId: 'getSwissVatReturn', summary: 'Get a Swiss VAT return', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Swiss VAT return')])]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new SwissVatReturnResource($this->workflow->find($this->tenantId(), $id)));
    }

    #[OA\Get(path: '/api/v1/swiss-vat/returns/{id}/reconciliation', operationId: 'getSwissVatReturnReconciliation', summary: 'Get AFC box reconciliation & quadratura report', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'AFC box schedule and quadratura report')])]
    public function reconciliation(string $id): JsonResponse
    {
        $report = $this->reconciliationHandler->handle(new GetSwissVatReconciliationQuery($this->tenantId(), $id));

        return $this->successResponse($report);
    }

    #[OA\Post(path: '/api/v1/swiss-vat/returns/{id}/validate', operationId: 'validateSwissVatReturnXml', summary: 'Validate Swiss VAT return against SuisseTax eCH-0217 XSD and quadratura rules', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Validation result')])]
    public function validateXml(EmptySwissVatActionRequest $request, string $id): JsonResponse
    {
        $result = $this->workflow->validateXml($this->tenantId(), $id);

        return $this->successResponse($result->toArray());
    }

    #[OA\Post(path: '/api/v1/swiss-vat/returns/{id}/finalize', operationId: 'finalizeSwissVatReturn', summary: 'Finalize and lock a validated Swiss VAT return', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Return finalized'), new OA\Response(response: 422, description: 'Quadratura failed')])]
    public function finalize(EmptySwissVatActionRequest $request, string $id): JsonResponse
    {
        $vatReturn = $this->finalizeHandler->handle(new FinalizeSwissVatReturnCommand(
            tenantId: $this->tenantId(),
            returnId: $id,
            actorUserId: $this->userId($request),
        ));

        return $this->successResponse(new SwissVatReturnResource($vatReturn), __('swiss_vat.messages.return_finalized'));
    }

    #[OA\Post(path: '/api/v1/swiss-vat/returns/{id}/export-xml', operationId: 'exportSwissVatReturnXml', summary: 'Validate and generate a SuisseTax XML export audit record', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Export record generated'), new OA\Response(response: 422, description: 'XSD or quadratura validation failed')])]
    public function exportXml(EmptySwissVatActionRequest $request, string $id): JsonResponse
    {
        $export = $this->exportHandler->handle(new ExportSuisseTaxXmlCommand(
            tenantId: $this->tenantId(),
            returnId: $id,
            actorUserId: $this->userId($request),
        ));

        return $this->successResponse(new SwissVatXmlExportResource($export), __('swiss_vat.messages.xml_exported'));
    }

    #[OA\Get(path: '/api/v1/swiss-vat/returns/{id}/exports', operationId: 'listSwissVatReturnExports', summary: 'List SuisseTax XML export history for a return', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'XML export history')])]
    public function exports(string $id): JsonResponse
    {
        $exports = $this->workflow->listExports($this->tenantId(), $id);

        return $this->successResponse(SwissVatXmlExportResource::collection($exports));
    }

    #[OA\Get(path: '/api/v1/swiss-vat/exports/{id}/download', operationId: 'downloadSwissVatXmlExport', summary: 'Download a previously generated SuisseTax XML file', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'SuisseTax eCH-0217 XML file', content: new OA\MediaType(mediaType: 'application/xml'))])]
    public function downloadExport(string $id): HttpResponse
    {
        $export = $this->workflow->findExport($this->tenantId(), $id);

        return $this->download((string) $export->xml_payload, (string) $export->file_name, 'application/xml');
    }
}
