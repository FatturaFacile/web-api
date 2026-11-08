<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Contracts\Enums\ContractKind;
use Modules\Contracts\Services\ContractRegister;
use Modules\Documents\Http\Resources\DocumentResource;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsDocumentLink;
use Modules\Documents\Services\DocumentAccess;
use Modules\Documents\Services\DocumentLibrary;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attachments of a contract: documents of the document management module linked to it. The files, their versions,
 * retention and audit trail stay in the Documents module; here a contract only lists, links and unlinks them.
 */
final class ContractDocumentController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(
        private readonly ContractRegister $register,
        private readonly DocumentAccess $access,
        private readonly DocumentLibrary $library,
    ) {}

    /**
     * List the documents of a contract.
     */
    #[OA\Get(
        path: '/api/v1/contract-register/{kind}/{id}/documents',
        operationId: 'listContractDocuments',
        description: 'The documents attached to the contract, limited to the ones the role of the caller can see in the document management module.',
        summary: 'List contract documents',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Contract Documents'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ContractKindPath'), new OA\Parameter(ref: '#/components/parameters/ContractIdPath')],
        responses: [
            new OA\Response(response: 200, description: 'Documents', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DmsDocumentResource'))])),
            new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        ]
    )]
    public function index(Request $request, string $kind, string $id): JsonResponse
    {
        $contract = $this->register->find(ContractKind::from($kind), $id);

        $documents = $this->access->restrict(DmsDocument::query(), $request->user())
            ->whereHas('links', fn ($links) => $links->where('linkable_type', $this->alias($kind))->where('linkable_id', $contract->getKey()))
            ->with(['tags', 'versions'])
            ->latest()
            ->get();

        return $this->successResponse(DocumentResource::collection($documents), 'Contract documents');
    }

    /**
     * Attach a document.
     */
    #[OA\Post(
        path: '/api/v1/contract-register/{kind}/{id}/documents',
        operationId: 'attachContractDocument',
        description: 'Attaches a document of the document management module to the contract (upload it there first). Attaching twice changes nothing. The attachment is recorded in the audit trail of the document.',
        summary: 'Attach a document to a contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractLinkDocumentRequest')),
        tags: ['Contract Documents'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ContractKindPath'), new OA\Parameter(ref: '#/components/parameters/ContractIdPath')],
        responses: [
            new OA\Response(response: 201, description: 'Attached', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsDocumentResource')])),
            new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
        ]
    )]
    public function store(Request $request, string $kind, string $id): JsonResponse
    {
        $contract = $this->register->find(ContractKind::from($kind), $id);
        $data = $request->validate(['document_id' => ['required', 'uuid', Rule::exists('dms_documents', 'id')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')]]);
        $document = $this->access->findVisible($data['document_id'], $request->user());

        $this->library->link($document, $this->alias($kind), (string) $contract->getKey(), $request->user());

        return $this->successResponse(new DocumentResource($document->load(['tags', 'versions'])), 'Document attached', Response::HTTP_CREATED);
    }

    /**
     * Detach a document.
     */
    #[OA\Delete(
        path: '/api/v1/contract-register/{kind}/{id}/documents/{documentId}',
        operationId: 'detachContractDocument',
        description: 'Removes the attachment. The document itself, its versions and its retention stay in the document management module.',
        summary: 'Detach a document from a contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Contract Documents'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ContractKindPath'), new OA\Parameter(ref: '#/components/parameters/ContractIdPath'), new OA\Parameter(name: 'documentId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Detached', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        ]
    )]
    public function destroy(Request $request, string $kind, string $id, string $documentId): JsonResponse
    {
        $contract = $this->register->find(ContractKind::from($kind), $id);
        $document = $this->access->findVisible($documentId, $request->user());
        $link = DmsDocumentLink::query()
            ->where('document_id', $document->id)
            ->where('linkable_type', $this->alias($kind))
            ->where('linkable_id', $contract->getKey())
            ->firstOrFail();

        $this->library->unlink($document, $link);

        return $this->successResponse(null, 'Document detached');
    }

    /**
     * The alias the document registry uses for the kind of contract.
     */
    private function alias(string $kind): string
    {
        return $kind === ContractKind::Customer->value ? 'service_contract' : 'supplier_contract';
    }
}
