<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Portal\PortalTokenService;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Modules\ServiceManagement\Http\Requests\SearchKnowledgeArticlesRequest;
use Modules\ServiceManagement\Http\Resources\KnowledgeCategoryResource;
use Modules\ServiceManagement\Http\Resources\PortalKnowledgeArticleResource;
use Modules\ServiceManagement\Models\KnowledgeArticle;
use Modules\ServiceManagement\Models\KnowledgeCategory;
use OpenApi\Attributes as OA;

/**
 * Public read API of the knowledge base for the customer portal: published public articles of the tenant the
 * magic-link token belongs to.
 */
final class PortalKnowledgeBaseController extends Controller
{
    public function __construct(private readonly PortalTokenService $tokenService) {}

    #[OA\Get(path: '/api/v1/portal/kb/{token}/categories', operationId: 'listPortalKnowledgeCategories', description: 'Lists the public knowledge base categories.', summary: 'Portal knowledge categories', security: [], tags: ['Customer Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskPortalToken')], responses: [
        new OA\Response(response: 200, description: 'Categories', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskKnowledgeCategoryResource'))])),
        new OA\Response(response: 404, description: 'Invalid link', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function categories(string $token): JsonResponse
    {
        $access = $this->tokenService->resolveToken($token);

        return response()->json(['data' => KnowledgeCategoryResource::collection(KnowledgeCategory::query()->where('tenant_id', $access->tenant_id)->where('visibility', KnowledgeArticle::VisibilityPublic)->orderBy('position')->orderBy('name')->get())]);
    }

    #[OA\Get(path: '/api/v1/portal/kb/{token}/articles', operationId: 'listPortalKnowledgeArticles', description: 'Lists or searches the published public articles; with q the best matches come first.', summary: 'Search portal knowledge base', security: [], tags: ['Customer Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskPortalToken'), new OA\Parameter(ref: '#/components/parameters/HelpdeskSearchQuery'), new OA\Parameter(name: 'knowledge_category_id', description: 'Category', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(ref: '#/components/parameters/HelpdeskPage')], responses: [
        new OA\Response(response: 200, description: 'Articles without their content', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskPortalKnowledgeArticleResource'))])),
        new OA\Response(response: 404, description: 'Invalid link', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function articles(SearchKnowledgeArticlesRequest $request, string $token): JsonResponse
    {
        $access = $this->tokenService->resolveToken($token);
        $filters = $request->validated();

        $query = KnowledgeArticle::query()
            ->where('tenant_id', $access->tenant_id)
            ->publiclyVisible()
            ->when(isset($filters['knowledge_category_id']), fn ($q) => $q->where('knowledge_category_id', $filters['knowledge_category_id']));

        $query = ! empty($filters['q']) ? $query->search($filters['q']) : $query->orderByDesc('published_at');

        return response()->json(['data' => PortalKnowledgeArticleResource::collection($query->gridPaginate(RequestedPerPage::resolve(20)))]);
    }

    #[OA\Get(path: '/api/v1/portal/kb/{token}/articles/{id}', operationId: 'showPortalKnowledgeArticle', description: 'Returns a published public article with its content and counts the view.', summary: 'Read portal knowledge article', security: [], tags: ['Customer Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskPortalToken'), new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], responses: [
        new OA\Response(response: 200, description: 'Article', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskPortalKnowledgeArticleResource')])),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function show(string $token, string $id): JsonResponse
    {
        $access = $this->tokenService->resolveToken($token);
        $article = KnowledgeArticle::query()->where('tenant_id', $access->tenant_id)->publiclyVisible()->findOrFail($id);
        KnowledgeArticle::query()->whereKey($article->id)->increment('views_count');

        $resource = new PortalKnowledgeArticleResource($article);
        $resource->withContent = true;

        return response()->json(['data' => $resource]);
    }
}
