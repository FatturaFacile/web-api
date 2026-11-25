<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\TenderManagement\Application\Commands\UpsertVaultDocumentCommand;
use Modules\TenderManagement\Application\Commands\UpsertVaultDocumentHandler;
use Modules\TenderManagement\Domain\Enums\VaultDocType;
use Modules\TenderManagement\Http\Controllers\Concerns\InteractsWithTenders;
use Modules\TenderManagement\Http\Requests\ListFiltersRequest;
use Modules\TenderManagement\Http\Requests\SaveVaultDocumentRequest;
use Modules\TenderManagement\Http\Resources\VaultDocumentResource;
use Modules\TenderManagement\Models\QualificationVaultDocument;
use Modules\TenderManagement\Services\VaultService;
use Modules\TenderManagement\Services\VaultStatusResolver;
use OpenApi\Attributes as OA;

/**
 * The company qualification vault.
 */
final class VaultController extends Controller
{
    use ApiResponse;
    use InteractsWithTenders;

    #[OA\Get(path: '/api/v1/tender-management/vault/overview', operationId: 'tenderManagementVaultOverview', summary: 'Traffic-light overview of the qualification vault', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], responses: [new OA\Response(response: 200, description: 'Vault overview by Italian and Swiss documents')])]
    public function overview(VaultService $vault): JsonResponse
    {
        Gate::authorize('viewAny', QualificationVaultDocument::class);

        $overview = $vault->overview(CarbonImmutable::today());

        foreach (['it', 'ch'] as $regime) {
            $overview[$regime]['documents'] = VaultDocumentResource::collection($overview[$regime]['documents']);
        }

        return $this->successResponse($overview);
    }

    #[OA\Get(path: '/api/v1/tender-management/vault/documents', operationId: 'tenderManagementListVaultDocuments', summary: 'List qualification vault documents', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], responses: [new OA\Response(response: 200, description: 'Vault documents, paginated')])]
    public function index(ListFiltersRequest $request, VaultService $vault, VaultStatusResolver $resolver): JsonResponse
    {
        Gate::authorize('viewAny', QualificationVaultDocument::class);

        $jurisdiction = $request->input('jurisdiction');
        $types = $jurisdiction === 'IT' || $jurisdiction === 'CH'
            ? array_values(array_map(static fn (VaultDocType $type): string => $type->value, array_filter(VaultDocType::cases(), static fn (VaultDocType $type): bool => $jurisdiction === 'IT' ? $type->isItalian() : $type->isSwiss())))
            : null;

        $page = $vault->activeQuery()
            ->when($request->filled('doc_type'), fn ($query) => $query->where('doc_type', $request->input('doc_type')))
            ->when($types !== null, fn ($query) => $query->whereIn('doc_type', $types))
            ->orderBy('doc_type')
            ->orderBy('expiry_date')
            ->paginate($this->perPage());

        if ($request->filled('verification_status')) {
            $today = CarbonImmutable::today();
            $items = array_values(array_filter($page->items(), fn (QualificationVaultDocument $document): bool => $resolver->statusAt($document, $today)->value === $request->input('verification_status')));

            return $this->successResponse(VaultDocumentResource::collection($items), $this->message('listed'), 200, ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => count($items), 'last_page' => $page->lastPage()]);
        }

        return $this->paged($page, VaultDocumentResource::class, $this->message('listed'));
    }

    #[OA\Post(path: '/api/v1/tender-management/vault/documents', operationId: 'tenderManagementCreateVaultDocument', summary: 'Add a document to the qualification vault', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], responses: [new OA\Response(response: 201, description: 'Document stored')])]
    public function store(SaveVaultDocumentRequest $request, UpsertVaultDocumentHandler $handler): JsonResponse
    {
        Gate::authorize('create', QualificationVaultDocument::class);

        $document = $handler->handle(new UpsertVaultDocumentCommand($request->safe()->except('file'), $request->file('file'), $this->userId()));

        return $this->successResponse(new VaultDocumentResource($document), $this->message('saved'), 201);
    }

    #[OA\Get(path: '/api/v1/tender-management/vault/documents/{id}', operationId: 'tenderManagementShowVaultDocument', summary: 'Qualification vault document', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Document')])]
    public function show(string $id): JsonResponse
    {
        $document = QualificationVaultDocument::query()->findOrFail($id);
        Gate::authorize('view', $document);

        return $this->successResponse(new VaultDocumentResource($document));
    }

    #[OA\Put(path: '/api/v1/tender-management/vault/documents/{id}', operationId: 'tenderManagementUpdateVaultDocument', summary: 'Update a vault document', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Document updated')])]
    public function update(SaveVaultDocumentRequest $request, string $id, UpsertVaultDocumentHandler $handler): JsonResponse
    {
        Gate::authorize('update', QualificationVaultDocument::query()->findOrFail($id));

        $document = $handler->handle(new UpsertVaultDocumentCommand($request->safe()->except(['file', 'doc_type']), $request->file('file'), $this->userId(), $id));

        return $this->successResponse(new VaultDocumentResource($document), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/tender-management/vault/documents/{id}', operationId: 'tenderManagementDeleteVaultDocument', summary: 'Remove a vault document', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Document removed')])]
    public function destroy(string $id, VaultService $vault): JsonResponse
    {
        $document = QualificationVaultDocument::query()->findOrFail($id);
        Gate::authorize('delete', $document);
        $vault->delete($document);

        return $this->successResponse(null, $this->message('deleted'));
    }

    #[OA\Post(path: '/api/v1/tender-management/vault/documents/{id}/renew', operationId: 'tenderManagementRenewVaultDocument', summary: 'Record the renewal of a vault document', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Renewed document')])]
    public function renew(SaveVaultDocumentRequest $request, string $id, UpsertVaultDocumentHandler $handler): JsonResponse
    {
        Gate::authorize('update', QualificationVaultDocument::query()->findOrFail($id));

        $document = $handler->handle(new UpsertVaultDocumentCommand($request->safe()->except(['file', 'doc_type']), $request->file('file'), $this->userId(), $id, true));

        return $this->successResponse(new VaultDocumentResource($document), $this->message('renewed'), 201);
    }

    #[OA\Post(path: '/api/v1/tender-management/vault/documents/{id}/sync-fvoe', operationId: 'tenderManagementSyncFvoe', summary: 'Flag the SOA or DURC as aligned with ANAC FVOE 2.0', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Document synced')])]
    public function syncFvoe(string $id, VaultService $vault): JsonResponse
    {
        $document = QualificationVaultDocument::query()->findOrFail($id);
        Gate::authorize('update', $document);

        return $this->successResponse(new VaultDocumentResource($vault->syncFvoe($document)), $this->message('synced'));
    }
}
