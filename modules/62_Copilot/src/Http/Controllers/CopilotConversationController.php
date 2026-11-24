<?php

declare(strict_types=1);

namespace Modules\Copilot\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Copilot\Enums\MessageRole;
use Modules\Copilot\Http\Requests\CreateCopilotConversationRequest;
use Modules\Copilot\Http\Resources\CopilotConversationResource;
use Modules\Copilot\Models\CopilotConversation;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The conversations of the caller with the Copilot.
 */
final class CopilotConversationController extends Controller
{
    use ApiResponse;

    private const int ListLimit = 50;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(
        path: '/api/v1/copilot/conversations',
        operationId: 'listCopilotConversations',
        description: 'The latest conversations of the caller, most recently active first (at most 50).',
        summary: 'List own Copilot conversations',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Copilot'],
        responses: [
            new OA\Response(response: 200, description: 'Conversations', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CopilotConversationResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $conversations = CopilotConversation::query()
            ->where('tenant_id', (string) $this->tenantContext->getTenantId())
            ->where('user_id', (string) $request->user()?->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::ListLimit)
            ->get();

        return $this->successResponse(CopilotConversationResource::collection($conversations));
    }

    #[OA\Post(
        path: '/api/v1/copilot/conversations',
        operationId: 'createCopilotConversation',
        description: 'Starts an empty conversation, optionally bound to the ERP screen it was opened from. Prompts are then sent to its messages endpoint.',
        summary: 'Start a Copilot conversation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/CreateCopilotConversationRequest')),
        tags: ['Copilot'],
        responses: [
            new OA\Response(response: 201, description: 'Conversation', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CopilotConversationResource')])),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(CreateCopilotConversationRequest $request): JsonResponse
    {
        $conversation = CopilotConversation::query()->create([
            'tenant_id' => (string) $this->tenantContext->getTenantId(),
            'user_id' => (string) $request->user()?->id,
            'title' => (string) ($request->validated('title') ?? 'New conversation'),
            'locale' => (string) ($request->validated('locale') ?? app()->getLocale()),
            'context_route' => $request->validated('context_route'),
            'context_entity_type' => $request->validated('context_entity_type'),
            'context_entity_id' => $request->validated('context_entity_id'),
        ]);

        return $this->successResponse(new CopilotConversationResource($conversation), 'Conversation started', Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/copilot/conversations/{id}',
        operationId: 'getCopilotConversation',
        description: 'An own conversation with its messages, oldest first.',
        summary: 'Get a Copilot conversation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Copilot'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Conversation', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CopilotConversationResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        $conversation = $this->own($request, $id);
        $conversation->load(['messages' => static fn ($query) => $query->whereIn('role', [MessageRole::User->value, MessageRole::Assistant->value])]);

        return $this->successResponse(new CopilotConversationResource($conversation));
    }

    #[OA\Delete(
        path: '/api/v1/copilot/conversations/{id}',
        operationId: 'deleteCopilotConversation',
        description: 'Archives an own conversation (soft delete).',
        summary: 'Delete a Copilot conversation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Copilot'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->own($request, $id)->delete();

        return $this->successResponse(null, 'Conversation deleted');
    }

    private function own(Request $request, string $id): CopilotConversation
    {
        return CopilotConversation::query()
            ->where('tenant_id', (string) $this->tenantContext->getTenantId())
            ->where('user_id', (string) $request->user()?->id)
            ->whereKey($id)
            ->firstOrFail();
    }
}
