<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Contracts\Enums\ContractKind;
use Modules\Contracts\Http\Controllers\Concerns\InteractsWithContractAuthoring;
use Modules\Contracts\Http\Requests\SaveContractCommentRequest;
use Modules\Contracts\Http\Requests\StoreDocumentVersionRequest;
use Modules\Contracts\Http\Requests\StoreVersionFromTemplateRequest;
use Modules\Contracts\Http\Resources\ContractCommentResource;
use Modules\Contracts\Http\Resources\DocumentVersionResource;
use Modules\Contracts\Models\ContractComment;
use Modules\Contracts\Models\ContractDocumentVersion;
use Modules\Contracts\Models\ContractTemplate;
use Modules\Contracts\Services\ContractRegister;
use Modules\Contracts\Services\RedliningService;
use OpenApi\Attributes as OA;

/**
 * Redlining: the numbered versions of a contract text, the diff between two of them and the review comments.
 */
final class DocumentVersionController extends Controller
{
    use InteractsWithContractAuthoring;

    public function __construct(private readonly RedliningService $redlining, private readonly ContractRegister $register) {}

    #[OA\Get(path: '/api/v1/contract-authoring/contracts/{kind}/{id}/versions', operationId: 'listContractDocumentVersions', description: 'Versions of the contract text, newest first, with the number of open comments.', summary: 'List text versions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['customer', 'supplier'])), new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Versions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ContractDocumentVersionResource'))])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
    ])]
    public function index(string $kind, string $id): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ContractDocumentVersion::class);
        $this->register->find(ContractKind::from($kind), $id);

        return DocumentVersionResource::collection(
            ContractDocumentVersion::query()
                ->withCount(['comments as open_comments_count' => static fn ($query) => $query->where('status', 'open')])
                ->where('tenant_id', $this->tenantId())
                ->where('contract_kind', $kind)
                ->where('contract_id', $id)
                ->orderByDesc('version_no')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/contract-authoring/contracts/{kind}/{id}/versions', operationId: 'storeContractDocumentVersion', description: 'Store an edited text (source edit) or the counterparty text (source counterparty) as the next version; it joins the open negotiation round.', summary: 'Store a text version', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractDocumentVersionRequest')), tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['customer', 'supplier'])), new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 201, description: 'Version created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractDocumentVersionResource')])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
    ])]
    public function store(StoreDocumentVersionRequest $request, string $kind, string $id): JsonResponse
    {
        $this->authorize('create', ContractDocumentVersion::class);
        $data = $request->validated();
        $version = $this->redlining->addVersion(ContractKind::from($kind), $id, $data['body'], $data['source'] ?? 'edit', $this->userId(), $data['notes'] ?? null);

        return (new DocumentVersionResource($version))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/contract-authoring/contracts/{kind}/{id}/versions/from-template', operationId: 'storeContractDocumentVersionFromTemplate', description: 'Assemble the clauses of an active template (approved clauses only, optional ones on request, fallback wordings by rank) with the variables and store the result as the next version.', summary: 'Assemble a text from a template', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractTemplateAssembleRequest')), tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['customer', 'supplier'])), new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 201, description: 'Version created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractDocumentVersionResource')])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
    ])]
    public function fromTemplate(StoreVersionFromTemplateRequest $request, string $kind, string $id): JsonResponse
    {
        $this->authorize('create', ContractDocumentVersion::class);
        $data = $request->validated();
        $template = ContractTemplate::query()->findOrFail($data['template_id']);
        $version = $this->redlining->addVersionFromTemplate(ContractKind::from($kind), $id, $template, $data['variables'] ?? [], $data['include_optional'] ?? [], $data['fallback_ranks'] ?? [], $this->userId());

        return (new DocumentVersionResource($version))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/contract-authoring/contracts/{kind}/{id}/versions/compare', operationId: 'compareContractDocumentVersions', description: 'Line diff between two versions of the contract (query from and to are version numbers, from is the older text).', summary: 'Compare two text versions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['customer', 'supplier'])), new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Diff'),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
    ])]
    public function compare(Request $request, string $kind, string $id): JsonResponse
    {
        $this->authorize('viewAny', ContractDocumentVersion::class);
        $query = $request->validate(['from' => ['required', 'integer', 'min:1'], 'to' => ['required', 'integer', 'min:1']]);
        $this->register->find(ContractKind::from($kind), $id);
        $find = fn (int $number): ContractDocumentVersion => ContractDocumentVersion::query()->where('contract_kind', $kind)->where('contract_id', $id)->where('version_no', $number)->firstOrFail();

        return response()->json(['data' => $this->redlining->compare($find((int) $query['from']), $find((int) $query['to']))]);
    }

    #[OA\Get(path: '/api/v1/contract-authoring/document-versions/{id}', operationId: 'showContractDocumentVersion', description: 'One version of the contract text with its body.', summary: 'Show a text version', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Version', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractDocumentVersionResource')])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
    ])]
    public function show(string $id): DocumentVersionResource
    {
        $version = ContractDocumentVersion::query()->findOrFail($id);
        $this->authorize('view', $version);

        return new DocumentVersionResource($version);
    }

    #[OA\Get(path: '/api/v1/contract-authoring/document-versions/{id}/comments', operationId: 'listContractComments', description: 'Review comments on a version, oldest first.', summary: 'List comments', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Comments', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ContractCommentResource'))])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
    ])]
    public function comments(string $id): AnonymousResourceCollection
    {
        $version = ContractDocumentVersion::query()->findOrFail($id);
        $this->authorize('view', $version);

        return ContractCommentResource::collection($version->comments()->orderBy('created_at')->get());
    }

    #[OA\Post(path: '/api/v1/contract-authoring/document-versions/{id}/comments', operationId: 'storeContractComment', description: 'Add a review comment, optionally anchored to a line.', summary: 'Add a comment', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractCommentRequest')), tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 201, description: 'Comment created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractCommentResource')])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
    ])]
    public function addComment(SaveContractCommentRequest $request, string $id): JsonResponse
    {
        $version = ContractDocumentVersion::query()->findOrFail($id);
        $this->authorize('update', $version);

        return (new ContractCommentResource($this->redlining->comment($version, $request->validated(), $this->userId())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/contract-authoring/contract-comments/{id}/resolve', operationId: 'resolveContractComment', description: 'Mark the comment as resolved.', summary: 'Resolve a comment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Comment', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractCommentResource')])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
    ])]
    public function resolveComment(string $id): ContractCommentResource
    {
        $comment = ContractComment::query()->findOrFail($id);
        $this->authorize('update', $comment);

        return new ContractCommentResource($this->redlining->resolveComment($comment, $this->userId()));
    }
}
