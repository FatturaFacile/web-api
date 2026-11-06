<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Controllers;

use App\Models\DeliveryNote;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Customs\Enums\DeclarationType;
use Modules\Customs\Exceptions\CustomsException;
use Modules\Customs\Http\Requests\FromDeliveryNoteRequest;
use Modules\Customs\Http\Requests\SaveDeclarationRequest;
use Modules\Customs\Http\Resources\CustomsRecordResource;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Customs\Models\CustomsDeclarationItem;
use Modules\Customs\Services\CustomsDeclarationService;
use OpenApi\Attributes as OA;

/**
 * Import, export and transit declarations.
 */
final class DeclarationController extends CustomsController
{
    public function __construct(TenantContext $tenantContext, private readonly CustomsDeclarationService $declarations)
    {
        parent::__construct($tenantContext);
    }

    /**
     * List declarations.
     */
    #[OA\Get(path: '/api/v1/customs/declarations', operationId: 'listCustomsDeclarations', description: 'Return the declarations of the company, newest first.', summary: 'List customs declarations', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [
        new OA\Parameter(name: 'declaration_type', description: 'import, export or transit', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'status', description: 'Only this status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'mrn', description: 'Only this MRN', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
    ], responses: [
        new OA\Response(response: 200, description: 'Declarations', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CustomsDeclaration::class);

        $query = CustomsDeclaration::query()
            ->when($request->filled('declaration_type'), static fn ($query) => $query->where('declaration_type', (string) $request->query('declaration_type')))
            ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
            ->when($request->filled('mrn'), static fn ($query) => $query->where('mrn', (string) $request->query('mrn')))
            ->latest();

        return CustomsRecordResource::collection($query->paginate($this->perPage($request)));
    }

    /**
     * Create a declaration.
     */
    #[OA\Post(path: '/api/v1/customs/declarations', operationId: 'storeCustomsDeclaration', description: 'Create a draft declaration with its lines. The totals are computed from the lines.', summary: 'Create customs declaration', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CustomsDeclarationRequest')), tags: ['Customs'], responses: [
        new OA\Response(response: 201, description: 'Declaration created', content: new OA\JsonContent(ref: '#/components/schemas/CustomsDeclarationResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function store(SaveDeclarationRequest $request): JsonResponse
    {
        $this->authorize('create', CustomsDeclaration::class);

        return (new CustomsRecordResource($this->declarations->create($request->declarationData(), $this->actorId($request))))->response()->setStatusCode(201);
    }

    /**
     * Create a declaration from a delivery note.
     */
    #[OA\Post(path: '/api/v1/customs/declarations/from-delivery-note', operationId: 'storeCustomsDeclarationFromDeliveryNote', description: 'Create a draft from a delivery note: consignee, lines and the tariff code of the products.', summary: 'Create declaration from delivery note', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CustomsFromDeliveryNoteRequest')), tags: ['Customs'], responses: [
        new OA\Response(response: 201, description: 'Declaration created', content: new OA\JsonContent(ref: '#/components/schemas/CustomsDeclarationResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function fromDeliveryNote(FromDeliveryNoteRequest $request): JsonResponse
    {
        $this->authorize('create', CustomsDeclaration::class);

        $note = DeliveryNote::query()->findOrFail($request->validated('delivery_note_id'));
        $declaration = $this->declarations->createFromDeliveryNote($note, DeclarationType::from($request->validated('declaration_type')), $this->actorId($request));

        return (new CustomsRecordResource($declaration))->response()->setStatusCode(201);
    }

    /**
     * Show a declaration.
     */
    #[OA\Get(path: '/api/v1/customs/declarations/{id}', operationId: 'showCustomsDeclaration', description: 'Return a declaration with its lines.', summary: 'Show customs declaration', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Declaration', content: new OA\JsonContent(ref: '#/components/schemas/CustomsDeclarationResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
    ])]
    public function show(string $id): CustomsRecordResource
    {
        $declaration = $this->find($id);
        $this->authorize('view', $declaration);

        return new CustomsRecordResource($declaration->load('items'));
    }

    /**
     * Change a declaration.
     */
    #[OA\Put(path: '/api/v1/customs/declarations/{id}', operationId: 'updateCustomsDeclaration', description: 'Change a declaration not yet submitted. It goes back to draft and must be validated again; lines, when given, replace the existing ones.', summary: 'Change customs declaration', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CustomsDeclarationRequest')), tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Declaration changed', content: new OA\JsonContent(ref: '#/components/schemas/CustomsDeclarationResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function update(SaveDeclarationRequest $request, string $id): CustomsRecordResource
    {
        $declaration = $this->find($id);
        $this->authorize('update', $declaration);

        return new CustomsRecordResource($this->declarations->update($declaration, $request->declarationData()));
    }

    /**
     * Delete a draft declaration.
     */
    #[OA\Delete(path: '/api/v1/customs/declarations/{id}', operationId: 'destroyCustomsDeclaration', description: 'Delete a declaration never sent to customs.', summary: 'Delete customs declaration', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 204, description: 'Declaration deleted'),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $declaration = $this->find($id);
        $this->authorize('delete', $declaration);

        if ($declaration->submitted_at !== null) {
            throw new CustomsException('A declaration sent to customs cannot be deleted.');
        }

        $declaration->delete();

        return response()->noContent();
    }

    /**
     * Validate a declaration.
     */
    #[OA\Post(path: '/api/v1/customs/declarations/{id}/validate', operationId: 'validateCustomsDeclaration', description: 'Check the declaration against the code tables and the published e-dec schema. Without errors it becomes validated.', summary: 'Validate customs declaration', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Outcome of the checks', content: new OA\JsonContent(ref: '#/components/schemas/CustomsValidationResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function validateDeclaration(string $id): JsonResponse
    {
        $declaration = $this->find($id);
        $this->authorize('update', $declaration);

        return response()->json(['data' => $this->declarations->validate($declaration)]);
    }

    /**
     * Submit a declaration.
     */
    #[OA\Post(path: '/api/v1/customs/declarations/{id}/submit', operationId: 'submitCustomsDeclaration', description: 'Hand a validated declaration to the customs channel of the company. The e-dec channel refuses unless the installation has been configured for live transmission.', summary: 'Submit customs declaration', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Answer of customs', content: new OA\JsonContent(ref: '#/components/schemas/CustomsDeclarationResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
        new OA\Response(ref: '#/components/responses/CustomsChannelError', response: 502),
    ])]
    public function submit(string $id): CustomsRecordResource
    {
        $declaration = $this->find($id);
        $this->authorize('update', $declaration);

        return new CustomsRecordResource($this->declarations->submit($declaration, $this->tenant())->load('items'));
    }

    /**
     * Cancel a declaration.
     */
    #[OA\Post(path: '/api/v1/customs/declarations/{id}/cancel', operationId: 'cancelCustomsDeclaration', description: 'Cancel a declaration not yet accepted. An accepted declaration is corrected or annulled at customs.', summary: 'Cancel customs declaration', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Declaration cancelled', content: new OA\JsonContent(ref: '#/components/schemas/CustomsDeclarationResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function cancel(string $id): CustomsRecordResource
    {
        $declaration = $this->find($id);
        $this->authorize('update', $declaration);

        return new CustomsRecordResource($this->declarations->cancel($declaration));
    }

    /**
     * Search the tariff lines used before.
     */
    #[OA\Get(path: '/api/v1/customs/tariff-history', operationId: 'listCustomsTariffHistory', description: 'Return the tariff lines of earlier declarations, most recent first, to reuse them.', summary: 'Search tariff line history', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [
        new OA\Parameter(name: 'search', description: 'Part of the description', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'commodity_code', description: 'Start of the tariff number', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'origin_country', description: 'Country of origin', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'consignee_contact_id', description: 'Only for this consignee', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Tariff lines', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
    ])]
    public function tariffHistory(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomsDeclaration::class);

        $page = $this->declarations->tariffHistory($request->only(['search', 'commodity_code', 'origin_country', 'consignee_contact_id']), $this->perPage($request));

        return response()->json(['data' => $page->items(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    /**
     * Add a line from the history.
     */
    #[OA\Post(path: '/api/v1/customs/declarations/{id}/items/reuse/{itemId}', operationId: 'reuseCustomsDeclarationItem', description: 'Add to the declaration a line copied from an earlier one, with its customs data but without masses and values.', summary: 'Reuse a tariff line', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId'), new OA\Parameter(name: 'itemId', description: 'The line to copy', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Line added', content: new OA\JsonContent(ref: '#/components/schemas/CustomsDeclarationResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function reuseItem(string $id, string $itemId): CustomsRecordResource
    {
        $declaration = $this->find($id);
        $this->authorize('update', $declaration);

        return new CustomsRecordResource($this->declarations->reuseItem($declaration, CustomsDeclarationItem::query()->findOrFail($itemId)));
    }

    /**
     * Show the customs message.
     */
    #[OA\Get(path: '/api/v1/customs/declarations/{id}/message', operationId: 'customsDeclarationMessage', description: 'Return the goodsDeclarations XML of the e-dec schema v4.0 built from the declaration. Transit has no such message.', summary: 'Customs declaration message', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'XML message', content: new OA\MediaType(mediaType: 'application/xml', schema: new OA\Schema(type: 'string'))),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function message(string $id): Response
    {
        $declaration = $this->find($id);
        $this->authorize('view', $declaration);

        return response($this->declarations->message($declaration), 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * Download the PDF copy.
     */
    #[OA\Get(path: '/api/v1/customs/declarations/{id}/pdf', operationId: 'customsDeclarationPdf', description: 'Return an internal PDF copy of the declaration. It is not a customs document.', summary: 'Customs declaration PDF', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'PDF', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
    ])]
    public function pdf(string $id): Response
    {
        $declaration = $this->find($id);
        $this->authorize('view', $declaration);

        return $this->fileResponse($this->declarations->renderPdf($declaration, $this->tenant()), $declaration->trader_declaration_number.'.pdf', 'application/pdf');
    }

    private function find(string $id): CustomsDeclaration
    {
        return CustomsDeclaration::query()->findOrFail($id);
    }
}
