<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ServiceManagement\Http\Requests\LinkKnowledgeArticleRequest;
use Modules\ServiceManagement\Http\Requests\SaveKnowledgeArticleRequest;
use Modules\ServiceManagement\Http\Requests\SaveKnowledgeCategoryRequest;
use Modules\ServiceManagement\Http\Requests\SearchKnowledgeArticlesRequest;
use Modules\ServiceManagement\Http\Resources\KnowledgeArticleResource;
use Modules\ServiceManagement\Http\Resources\KnowledgeArticleVersionResource;
use Modules\ServiceManagement\Http\Resources\KnowledgeCategoryResource;
use Modules\ServiceManagement\Models\KnowledgeArticle;
use Modules\ServiceManagement\Models\KnowledgeArticleVersion;
use Modules\ServiceManagement\Models\KnowledgeCategory;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketKnowledgeLink;
use Modules\ServiceManagement\Services\Helpdesk\KnowledgeBaseService;
use OpenApi\Attributes as OA;

/**
 * Knowledge base for staff: categories, articles with versions, search, and links to tickets.
 */
final class KnowledgeBaseController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly KnowledgeBaseService $knowledgeBase,
    ) {}

    #[OA\Get(path: '/api/v1/esm/kb/categories', operationId: 'listEsmKnowledgeCategories', description: 'Lists the knowledge base categories of the tenant.', summary: 'List knowledge categories', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Knowledge Base'], responses: [
        new OA\Response(response: 200, description: 'Categories', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskKnowledgeCategoryResource'))])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function categories(): JsonResponse
    {
        $this->authorize('viewAny', KnowledgeCategory::class);

        return response()->json(['data' => KnowledgeCategoryResource::collection(KnowledgeCategory::query()->where('tenant_id', $this->tenantId())->orderBy('position')->orderBy('name')->get())]);
    }

    #[OA\Post(path: '/api/v1/esm/kb/categories', operationId: 'storeEsmKnowledgeCategory', description: 'Adds a knowledge base category. A public category shows its public articles on the portal.', summary: 'Add knowledge category', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Knowledge Base'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskSaveKnowledgeCategoryRequest')), responses: [
        new OA\Response(response: 201, description: 'Category added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskKnowledgeCategoryResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function storeCategory(SaveKnowledgeCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', KnowledgeCategory::class);

        return response()->json(['data' => new KnowledgeCategoryResource(KnowledgeCategory::query()->create([...$request->validated(), 'tenant_id' => $this->tenantId()]))], 201);
    }

    #[OA\Patch(path: '/api/v1/esm/kb/categories/{id}', operationId: 'updateEsmKnowledgeCategory', description: 'Changes a knowledge base category.', summary: 'Change knowledge category', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Knowledge Base'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskSaveKnowledgeCategoryRequest')), responses: [
        new OA\Response(response: 200, description: 'Category changed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskKnowledgeCategoryResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function updateCategory(SaveKnowledgeCategoryRequest $request, string $id): JsonResponse
    {
        $category = KnowledgeCategory::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $category);
        $category->update($request->validated());

        return response()->json(['data' => new KnowledgeCategoryResource($category->refresh())]);
    }

    #[OA\Delete(path: '/api/v1/esm/kb/categories/{id}', operationId: 'deleteEsmKnowledgeCategory', description: 'Removes a knowledge base category; its articles stay without category.', summary: 'Remove knowledge category', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Knowledge Base'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], responses: [
        new OA\Response(response: 204, description: 'Removed'),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function destroyCategory(string $id): JsonResponse
    {
        $category = KnowledgeCategory::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('delete', $category);
        KnowledgeArticle::query()->where('knowledge_category_id', $category->id)->update(['knowledge_category_id' => null]);
        $category->delete();

        return response()->json(null, 204);
    }

    #[OA\Get(path: '/api/v1/esm/kb/articles', operationId: 'listEsmKnowledgeArticles', description: 'Lists or searches the articles of the tenant, internal and public. With q the full-text search ranks the best matches first.', summary: 'Search knowledge articles', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Knowledge Base'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskSearchQuery'), new OA\Parameter(name: 'visibility', description: 'internal or public', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['internal', 'public'])), new OA\Parameter(name: 'knowledge_category_id', description: 'Category', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'is_published', description: 'Published or draft', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')), new OA\Parameter(ref: '#/components/parameters/HelpdeskPage')], responses: [
        new OA\Response(response: 200, description: 'Articles', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskKnowledgeArticleResource'))])),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function articles(SearchKnowledgeArticlesRequest $request): JsonResponse
    {
        $this->authorize('viewAny', KnowledgeArticle::class);
        $filters = $request->validated();

        $query = KnowledgeArticle::query()
            ->where('tenant_id', $this->tenantId())
            ->when(isset($filters['visibility']), fn ($q) => $q->where('visibility', $filters['visibility']))
            ->when(isset($filters['knowledge_category_id']), fn ($q) => $q->where('knowledge_category_id', $filters['knowledge_category_id']))
            ->when(array_key_exists('is_published', $filters), fn ($q) => $q->where('is_published', (bool) $filters['is_published']));

        $query = ! empty($filters['q']) ? $query->search($filters['q']) : $query->orderByDesc('updated_at');

        return response()->json(['data' => KnowledgeArticleResource::collection($query->gridPaginate(RequestedPerPage::resolve(25)))]);
    }

    #[OA\Post(path: '/api/v1/esm/kb/articles', operationId: 'storeEsmKnowledgeArticle', description: 'Adds an article; version 1 is saved. Publishing a public article needs a service manager.', summary: 'Add knowledge article', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Knowledge Base'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskSaveKnowledgeArticleRequest')), responses: [
        new OA\Response(response: 201, description: 'Article added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskKnowledgeArticleResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function storeArticle(SaveKnowledgeArticleRequest $request): JsonResponse
    {
        $this->authorize('create', KnowledgeArticle::class);
        $data = $request->validated();
        $this->authorizePublication($data, null);

        return response()->json(['data' => new KnowledgeArticleResource($this->knowledgeBase->create($this->tenantId(), $data, $request->user()?->id))], 201);
    }

    #[OA\Get(path: '/api/v1/esm/kb/articles/{id}', operationId: 'showEsmKnowledgeArticle', description: 'Returns an article of the tenant.', summary: 'Show knowledge article', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Knowledge Base'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], responses: [
        new OA\Response(response: 200, description: 'Article', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskKnowledgeArticleResource')])),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function showArticle(string $id): JsonResponse
    {
        $article = $this->article($id);
        $this->authorize('view', $article);

        return response()->json(['data' => new KnowledgeArticleResource($article)]);
    }

    #[OA\Patch(path: '/api/v1/esm/kb/articles/{id}', operationId: 'updateEsmKnowledgeArticle', description: 'Changes an article. A change of title, summary or content saves a new version.', summary: 'Change knowledge article', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Knowledge Base'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskSaveKnowledgeArticleRequest')), responses: [
        new OA\Response(response: 200, description: 'Article changed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskKnowledgeArticleResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function updateArticle(SaveKnowledgeArticleRequest $request, string $id): JsonResponse
    {
        $article = $this->article($id);
        $this->authorize('update', $article);
        $data = $request->validated();
        $this->authorizePublication($data, $article);

        return response()->json(['data' => new KnowledgeArticleResource($this->knowledgeBase->update($article, $data, $request->user()?->id))]);
    }

    #[OA\Delete(path: '/api/v1/esm/kb/articles/{id}', operationId: 'deleteEsmKnowledgeArticle', description: 'Removes an article.', summary: 'Remove knowledge article', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Knowledge Base'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], responses: [
        new OA\Response(response: 204, description: 'Removed'),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function destroyArticle(string $id): JsonResponse
    {
        $article = $this->article($id);
        $this->authorize('delete', $article);
        $article->delete();

        return response()->json(null, 204);
    }

    #[OA\Get(path: '/api/v1/esm/kb/articles/{id}/versions', operationId: 'listEsmKnowledgeArticleVersions', description: 'Lists the saved versions of an article, newest first.', summary: 'Knowledge article versions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Knowledge Base'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], responses: [
        new OA\Response(response: 200, description: 'Versions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskKnowledgeArticleVersionResource'))])),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function versions(string $id): JsonResponse
    {
        $article = $this->article($id);
        $this->authorize('view', $article);

        return response()->json(['data' => KnowledgeArticleVersionResource::collection(KnowledgeArticleVersion::query()->where('article_id', $article->id)->orderByDesc('version')->get())]);
    }

    #[OA\Post(path: '/api/v1/esm/kb/articles/{id}/versions/{version}/restore', operationId: 'restoreEsmKnowledgeArticleVersion', description: 'Puts the text of an earlier version back, saved as a new version.', summary: 'Restore knowledge article version', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Knowledge Base'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath'), new OA\Parameter(name: 'version', description: 'Version number', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [
        new OA\Response(response: 200, description: 'Article restored', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskKnowledgeArticleResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Unknown version', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function restoreVersion(Request $request, string $id, int $version): JsonResponse
    {
        $article = $this->article($id);
        $this->authorize('update', $article);

        return response()->json(['data' => new KnowledgeArticleResource($this->knowledgeBase->restore($article, $version, $request->user()?->id))]);
    }

    #[OA\Get(path: '/api/v1/esm/tickets/{ticket}/knowledge-links', operationId: 'listEsmTicketKnowledgeLinks', description: 'Lists the articles linked to the ticket.', summary: 'Ticket knowledge links', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Knowledge Base'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath')], responses: [
        new OA\Response(response: 200, description: 'Linked articles', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskKnowledgeArticleResource'))])),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function ticketLinks(Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);
        $articleIds = TicketKnowledgeLink::query()->where('ticket_id', $ticket->id)->pluck('article_id');

        return response()->json(['data' => KnowledgeArticleResource::collection(KnowledgeArticle::query()->whereIn('id', $articleIds)->orderBy('title')->get())]);
    }

    #[OA\Post(path: '/api/v1/esm/tickets/{ticket}/knowledge-links', operationId: 'storeEsmTicketKnowledgeLink', description: 'Links an article of the tenant to the ticket.', summary: 'Link knowledge article', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Knowledge Base'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskLinkKnowledgeArticleRequest')), responses: [
        new OA\Response(response: 201, description: 'Linked', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskKnowledgeArticleResource')])),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function linkTicket(LinkKnowledgeArticleRequest $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('update', $ticket);
        $article = $this->article($request->validated('article_id'));
        $this->knowledgeBase->link($ticket, $article, $request->user()?->id);

        return response()->json(['data' => new KnowledgeArticleResource($article)], 201);
    }

    #[OA\Delete(path: '/api/v1/esm/tickets/{ticket}/knowledge-links/{id}', operationId: 'deleteEsmTicketKnowledgeLink', description: 'Removes the link between the ticket and an article.', summary: 'Unlink knowledge article', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Knowledge Base'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath'), new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], responses: [
        new OA\Response(response: 204, description: 'Unlinked'),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function unlinkTicket(Request $request, Ticket $ticket, string $id): JsonResponse
    {
        $this->authorize('update', $ticket);
        $this->knowledgeBase->unlink($ticket, $id, $request->user()?->id);

        return response()->json(null, 204);
    }

    /**
     * Publishing for customers (published and public) is reserved to service managers.
     *
     * @param  array<string, mixed>  $data
     */
    private function authorizePublication(array $data, ?KnowledgeArticle $article): void
    {
        $published = (bool) ($data['is_published'] ?? $article?->is_published ?? false);
        $visibility = $data['visibility'] ?? $article?->visibility ?? KnowledgeArticle::VisibilityInternal;

        if ($published && $visibility === KnowledgeArticle::VisibilityPublic) {
            $this->authorize('publishPublicly', KnowledgeArticle::class);
        }
    }

    private function article(string $id): KnowledgeArticle
    {
        return KnowledgeArticle::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }
}
