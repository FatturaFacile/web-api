<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Controllers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Customs\Exceptions\CustomsException;
use Modules\Customs\Http\Requests\AssignMrnRequest;
use Modules\Customs\Http\Requests\CustomsDocumentRequest;
use Modules\Customs\Http\Requests\LinkInvoicesRequest;
use Modules\Customs\Http\Resources\CustomsRecordResource;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Customs\Services\Documents\CustomsDocumentService;
use Modules\Customs\Services\ExportProofService;
use Modules\Customs\Support\Mrn;
use OpenApi\Attributes as OA;

/**
 * The Foreign Trade hub: dashboard, MRN assignment, invoice links, export proof and the bilingual customs documents.
 */
final class TradeComplianceController extends CustomsController
{
    public function __construct(
        TenantContext $tenantContext,
        private readonly ExportProofService $proof,
        private readonly CustomsDocumentService $documents,
    ) {
        parent::__construct($tenantContext);
    }

    /**
     * Foreign trade dashboard.
     */
    #[OA\Get(path: '/api/v1/customs/trade-compliance/dashboard', operationId: 'customsTradeComplianceDashboard', description: 'Declarations by direction, regime and status, transit past its time limit, and the export declarations still lacking their export assessment decision (eVV).', summary: 'Foreign trade dashboard', security: [['bearerAuth' => []]], tags: ['Customs'], responses: [
        new OA\Response(response: 200, description: 'Dashboard', content: new OA\JsonContent(type: 'object')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
    ])]
    public function dashboard(): JsonResponse
    {
        $this->authorize('viewAny', CustomsDeclaration::class);

        return response()->json(['data' => $this->proof->dashboard()]);
    }

    /**
     * Assign the MRN issued by customs.
     */
    #[OA\Post(path: '/api/v1/customs/declarations/{id}/mrn', operationId: 'assignCustomsDeclarationMrn', description: 'Record the MRN customs issued for a declaration lodged outside the application. A Union MRN has 18 characters, a Swiss one 18 to 21.', summary: 'Assign MRN', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['mrn'], properties: [new OA\Property(property: 'mrn', type: 'string')])), tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Declaration', content: new OA\JsonContent(ref: '#/components/schemas/CustomsDeclarationResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function assignMrn(AssignMrnRequest $request, string $id): CustomsRecordResource
    {
        $declaration = $this->find($id);
        $this->authorize('update', $declaration);

        $declaration->update(['mrn' => Mrn::normalize((string) $request->validated('mrn')), 'mrn_version' => $declaration->mrn_version ?? 1]);

        return new CustomsRecordResource($declaration);
    }

    /**
     * Link invoices to a declaration.
     */
    #[OA\Post(path: '/api/v1/customs/declarations/{id}/invoices', operationId: 'linkCustomsDeclarationInvoices', description: 'Link the invoices a declaration clears. The export decision of the declaration is then the proof of export of each invoice.', summary: 'Link invoices', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['invoice_ids'], properties: [new OA\Property(property: 'invoice_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))])), tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Declaration with its invoices', content: new OA\JsonContent(ref: '#/components/schemas/CustomsDeclarationResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function linkInvoices(LinkInvoicesRequest $request, string $id): CustomsRecordResource
    {
        $declaration = $this->find($id);
        $this->authorize('update', $declaration);

        return new CustomsRecordResource($this->proof->link($declaration, $request->validated('invoice_ids')));
    }

    /**
     * Link invoices by reference.
     */
    #[OA\Post(path: '/api/v1/customs/declarations/{id}/invoices/auto-link', operationId: 'autoLinkCustomsDeclarationInvoices', description: 'Link the invoices whose number is the trader reference or the declaration number.', summary: 'Auto-link invoices', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Number of invoices linked now', content: new OA\JsonContent(type: 'object')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
    ])]
    public function autoLink(string $id): JsonResponse
    {
        $declaration = $this->find($id);
        $this->authorize('update', $declaration);

        return response()->json(['data' => ['linked' => $this->proof->autoLink($declaration)]]);
    }

    /**
     * Proof of export of a declaration.
     */
    #[OA\Get(path: '/api/v1/customs/declarations/{id}/export-proof', operationId: 'customsDeclarationExportProof', description: 'The export assessment decision (eVV) that proves the export, or none when it has not arrived.', summary: 'Export proof', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Proof', content: new OA\JsonContent(type: 'object')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
    ])]
    public function exportProof(string $id): JsonResponse
    {
        $declaration = $this->find($id);
        $this->authorize('view', $declaration);
        $decision = $this->proof->decisionFor($declaration);

        return response()->json(['data' => ['has_proof' => $decision !== null, 'decision' => $decision === null ? null : new CustomsRecordResource($decision)]]);
    }

    /**
     * Mark a transit declaration as discharged.
     */
    #[OA\Post(path: '/api/v1/customs/declarations/{id}/transit-discharge', operationId: 'dischargeCustomsTransit', description: 'Record that the T1 / T2 transit was discharged at the office of destination.', summary: 'Discharge transit', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Declaration', content: new OA\JsonContent(ref: '#/components/schemas/CustomsDeclarationResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function dischargeTransit(string $id): CustomsRecordResource
    {
        $declaration = $this->find($id);
        $this->authorize('update', $declaration);

        if (! $declaration->transit_document_type->isTransit()) {
            throw new CustomsException('The declaration has no T1 or T2 transit document.');
        }

        $declaration->update(['transit_discharged_at' => $declaration->transit_discharged_at ?? now()]);

        return new CustomsRecordResource($declaration);
    }

    /**
     * Bilingual customs invoice.
     */
    #[OA\Get(path: '/api/v1/customs/declarations/{id}/pro-forma', operationId: 'customsDeclarationProForma', description: 'The bilingual commercial / pro-forma invoice for customs: Italian with English (it_en), German (it_de) or French (it_fr), with tariff codes, origin, weights, packages and, when eligible, the preferential origin text.', summary: 'Customs pro-forma invoice PDF', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/CustomsRecordId'),
        new OA\Parameter(name: 'language', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['it_en', 'it_de', 'it_fr'])),
    ], responses: [
        new OA\Response(response: 200, description: 'PDF', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
    ])]
    public function proForma(CustomsDocumentRequest $request, string $id): Response
    {
        $declaration = $this->find($id);
        $this->authorize('view', $declaration);
        $pair = (string) $request->validated('language', 'it_en');

        return $this->fileResponse($this->documents->renderProForma($declaration, $this->tenant(), $pair), $declaration->trader_declaration_number.'-pro-forma.pdf', 'application/pdf');
    }

    /**
     * T1 / T2 transit tracking sheet.
     */
    #[OA\Get(path: '/api/v1/customs/declarations/{id}/transit-sheet', operationId: 'customsDeclarationTransitSheet', description: 'The T1 / T2 transit tracking sheet: guarantee, offices, time limit and discharge status.', summary: 'Transit tracking sheet PDF', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/CustomsRecordId'),
        new OA\Parameter(name: 'language', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['it_en', 'it_de', 'it_fr'])),
    ], responses: [
        new OA\Response(response: 200, description: 'PDF', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function transitSheet(CustomsDocumentRequest $request, string $id): Response
    {
        $declaration = $this->find($id);
        $this->authorize('view', $declaration);
        $pair = (string) $request->validated('language', 'it_en');

        return $this->fileResponse($this->documents->renderTransitSheet($declaration, $this->tenant(), $pair), $declaration->trader_declaration_number.'-transit.pdf', 'application/pdf');
    }

    /**
     * Packing list.
     */
    #[OA\Get(path: '/api/v1/customs/declarations/{id}/packing-list', operationId: 'customsDeclarationPackingList', description: 'The multilingual packing list of a declaration: packages, net and gross weight per line, Incoterm, MRN and EORI / UID of the parties.', summary: 'Packing list PDF', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/CustomsRecordId'),
        new OA\Parameter(name: 'language', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['it_en', 'it_de', 'it_fr'])),
    ], responses: [
        new OA\Response(response: 200, description: 'PDF', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
    ])]
    public function packingList(CustomsDocumentRequest $request, string $id): Response
    {
        $declaration = $this->find($id);
        $this->authorize('view', $declaration);
        $pair = (string) $request->validated('language', 'it_en');

        return $this->fileResponse($this->documents->renderPackingList($declaration, $this->tenant(), $pair), $declaration->trader_declaration_number.'-packing-list.pdf', 'application/pdf');
    }

    private function find(string $id): CustomsDeclaration
    {
        return CustomsDeclaration::query()->findOrFail($id);
    }
}
