<?php

declare(strict_types=1);

namespace Modules\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Assistant\Contracts\CatalogueQuery;
use Modules\Assistant\Http\Requests\AskAssistantRequest;
use Modules\Assistant\Http\Resources\AssistantLogResource;
use Modules\Assistant\Models\AssistantQuestionLog;
use Modules\Assistant\Services\AssistantService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * HTTP endpoints of the assistant flows of the Assistant module.
 */
final class AssistantController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(
        private readonly AssistantService $assistant,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Ask the assistant.
     */
    #[OA\Post(
        path: '/api/v1/assistant/ask',
        operationId: 'askAssistant',
        description: 'Answer a question in natural language from the fixed catalogue. Read-only and limited to the role of the caller. Every question is logged.',
        summary: 'Ask the assistant',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AskAssistantRequest')),
        tags: ['Assistant'],
        responses: [
            new OA\Response(response: 200, description: 'Answer, or the reason why there is none', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssistantAnswer')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function ask(AskAssistantRequest $request): JsonResponse
    {
        return $this->successResponse($this->assistant->ask($request->user(), (string) $this->tenantContext->getTenantId(), $request->validated('question')));
    }

    /**
     * List the catalogue.
     */
    #[OA\Get(
        path: '/api/v1/assistant/catalogue',
        operationId: 'getAssistantCatalogue',
        description: 'The questions the role of the caller can ask.',
        summary: 'List the catalogue',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Assistant'],
        responses: [
            new OA\Response(response: 200, description: 'Catalogue', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssistantCatalogueEntry'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function catalogue(Request $request): JsonResponse
    {
        $queries = $this->assistant->availableTo($request->user(), (string) $this->tenantContext->getTenantId());

        return $this->successResponse(array_map(static fn (CatalogueQuery $query): array => [
            'key' => $query->key(),
            'description' => $query->description(),
            'parameters' => (object) $query->parameters(),
        ], $queries));
    }

    /**
     * List asked questions.
     */
    #[OA\Get(
        path: '/api/v1/assistant/logs',
        operationId: 'getAssistantLogs',
        description: 'Questions asked in this tenant. Owners and administrators only.',
        summary: 'List asked questions',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Assistant'],
        parameters: [new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Questions', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssistantLogResource'))])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function logs(Request $request): JsonResponse
    {
        $role = $request->user()->getRoleInTenant((string) $this->tenantContext->getTenantId());

        if (! in_array($role, ['owner', 'admin'], true)) {
            throw new AccessDeniedHttpException('Only owners and administrators can read the questions.');
        }

        $logs = AssistantQuestionLog::query()->latest()->paginate(min(100, max(5, (int) $request->query('per_page', 25))));

        return $this->successResponse(
            AssistantLogResource::collection($logs->getCollection()),
            'Questions',
            meta: ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'per_page' => $logs->perPage(), 'total' => $logs->total()]
        );
    }
}
