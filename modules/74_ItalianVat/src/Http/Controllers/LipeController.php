<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Modules\ItalianVat\Http\Controllers\Concerns\ResolvesItalianVatContext;
use Modules\ItalianVat\Http\Requests\AttachSignatureRequest;
use Modules\ItalianVat\Http\Requests\EmptyActionRequest;
use Modules\ItalianVat\Http\Requests\GenerateLipeRequest;
use Modules\ItalianVat\Http\Requests\LipeListRequest;
use Modules\ItalianVat\Http\Requests\RecordRejectionRequest;
use Modules\ItalianVat\Http\Requests\RecordTransmissionRequest;
use Modules\ItalianVat\Http\Resources\LipeCommunicationResource;
use Modules\ItalianVat\Services\LipeService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The quarterly LIPE communication: generation, schema check, reconciliation with the ledger, signature and transmission.
 */
final class LipeController extends Controller
{
    use ApiResponse;
    use ResolvesItalianVatContext;

    public function __construct(private readonly LipeService $lipe) {}

    #[OA\Get(path: '/api/v1/italian-vat/lipe', operationId: 'listItalianVatLipe', summary: 'List the LIPE communications', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], responses: [new OA\Response(response: 200, description: 'The communications, newest quarter first')])]
    public function index(LipeListRequest $request): JsonResponse
    {
        $year = $request->validated('year');

        return $this->successResponse(LipeCommunicationResource::collection($this->lipe->list($this->tenantId(), $year === null ? null : (int) $year)));
    }

    #[OA\Post(path: '/api/v1/italian-vat/lipe', operationId: 'generateItalianVatLipe', summary: 'Generate the LIPE communication of a quarter', description: 'Gathers the final periodic settlements of the quarter, builds the IVP18 file, validates it against the structural schema and reconciles it with the Erario c/IVA account.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], responses: [new OA\Response(response: 201, description: 'The communication'), new OA\Response(response: 409, description: 'Already transmitted'), new OA\Response(response: 422, description: 'The quarter is not completely settled')])]
    public function generate(GenerateLipeRequest $request): JsonResponse
    {
        $communication = $this->lipe->generate($this->tenantId(), $this->userId($request), (int) $request->validated('year'), (int) $request->validated('quarter'));

        return $this->successResponse(new LipeCommunicationResource($communication), 'LIPE generated', Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/italian-vat/lipe/{id}', operationId: 'getItalianVatLipe', summary: 'A LIPE communication', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The communication'), new OA\Response(response: 404, description: 'Unknown communication')])]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new LipeCommunicationResource($this->lipe->find($this->tenantId(), $id)));
    }

    #[OA\Get(path: '/api/v1/italian-vat/lipe/{id}/xml', operationId: 'downloadItalianVatLipeXml', summary: 'The LIPE XML file', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The XML file', content: new OA\MediaType(mediaType: 'application/xml'))])]
    public function xml(string $id): HttpResponse
    {
        $communication = $this->lipe->find($this->tenantId(), $id);

        return $this->download((string) $communication->xml_content, (string) $communication->file_name, 'application/xml');
    }

    #[OA\Post(path: '/api/v1/italian-vat/lipe/{id}/reconcile', operationId: 'reconcileItalianVatLipe', summary: 'Reconcile a LIPE communication with the Erario c/IVA account', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The communication with the reconciliation diagnostics')])]
    public function reconcile(EmptyActionRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new LipeCommunicationResource($this->lipe->refreshReconciliation($this->tenantId(), $id)), 'LIPE reconciled');
    }

    #[OA\Post(path: '/api/v1/italian-vat/lipe/{id}/signature', operationId: 'signItalianVatLipe', summary: 'Attach and verify the signature of the file', description: 'The file is signed outside the ERP with the certificate of the declarant or the intermediary. The detached RSA SHA-256 signature is verified against the certificate and the exact content of the file.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The signed communication'), new OA\Response(response: 422, description: 'The signature does not verify')])]
    public function signature(AttachSignatureRequest $request, string $id): JsonResponse
    {
        $communication = $this->lipe->attachSignature($this->tenantId(), $id, (string) $request->validated('signature'), (string) $request->validated('certificate'));

        return $this->successResponse(new LipeCommunicationResource($communication), 'LIPE signed');
    }

    #[OA\Post(path: '/api/v1/italian-vat/lipe/{id}/ready', operationId: 'markItalianVatLipeReady', summary: 'Declare the file ready for transmission', description: 'The file must be valid against the schema, signed on its current content and balanced with the ledger.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The ready communication'), new OA\Response(response: 422, description: 'Schema, signature or reconciliation problems')])]
    public function ready(EmptyActionRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new LipeCommunicationResource($this->lipe->markReady($this->tenantId(), $id)), 'LIPE ready for transmission');
    }

    #[OA\Post(path: '/api/v1/italian-vat/lipe/{id}/transmission', operationId: 'recordItalianVatLipeTransmission', summary: 'Record the receipt of the transmission', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The transmitted communication'), new OA\Response(response: 422, description: 'The file is not ready')])]
    public function transmission(RecordTransmissionRequest $request, string $id): JsonResponse
    {
        $at = $request->validated('transmitted_at');
        $communication = $this->lipe->recordTransmission($this->tenantId(), $id, (string) $request->validated('protocol'), $at === null ? null : CarbonImmutable::parse((string) $at));

        return $this->successResponse(new LipeCommunicationResource($communication), 'Transmission recorded');
    }

    #[OA\Post(path: '/api/v1/italian-vat/lipe/{id}/rejection', operationId: 'recordItalianVatLipeRejection', summary: 'Record that the Agenzia delle Entrate rejected the file', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The rejected communication')])]
    public function rejection(RecordRejectionRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new LipeCommunicationResource($this->lipe->recordRejection($this->tenantId(), $id, (string) $request->validated('reason'))), 'Rejection recorded');
    }
}
