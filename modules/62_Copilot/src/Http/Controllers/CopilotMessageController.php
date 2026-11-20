<?php

declare(strict_types=1);

namespace Modules\Copilot\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Enterprise\AiGateway\Exceptions\ExternalAiNotAllowedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Copilot\Commands\SendCopilotPromptCommand;
use Modules\Copilot\DTOs\PromptContext;
use Modules\Copilot\Http\Requests\SendCopilotMessageRequest;
use Modules\Copilot\Http\Resources\CopilotMessageResource;
use Modules\Copilot\Llm\CopilotProviderException;
use Modules\Copilot\Models\CopilotConversation;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Sends a prompt to the Copilot: answered as JSON, or streamed as server-sent events when the client asks for
 * `text/event-stream`.
 */
final class CopilotMessageController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SendCopilotPromptCommand $command,
        private readonly TenantContext $tenantContext,
    ) {}

    #[OA\Post(
        path: '/api/v1/copilot/conversations/{id}/messages',
        operationId: 'sendCopilotMessage',
        description: 'Sends a prompt in an own conversation. The Copilot reads data only within the permissions of the caller; a change is never executed here, it comes back as a pending `proposed_action` that must be confirmed. With `Accept: text/event-stream` the turn is streamed as events: message.started, tool.call, tool.result, visualization, action.proposed, token (text chunks), message.completed, and error.',
        summary: 'Send a prompt to the Copilot',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SendCopilotMessageRequest')),
        tags: ['Copilot'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The assistant message (JSON), or an event stream', content: [
                new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CopilotMessageResource')]),
                new OA\MediaType(mediaType: 'text/event-stream', schema: new OA\Schema(type: 'string')),
            ]),
            new OA\Response(response: 403, description: 'External AI providers are not allowed for this company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Conversation not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limit or daily token quota reached', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 502, description: 'The language model provider failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function store(SendCopilotMessageRequest $request, string $id): JsonResponse|StreamedResponse
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $user = $request->user();

        $conversation = CopilotConversation::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', (string) $user?->id)
            ->whereKey($id)
            ->firstOrFail();

        $context = new PromptContext(
            $request->validated('context.route'),
            $request->validated('context.entity_type'),
            $request->validated('context.entity_id'),
        );
        $locale = (string) ($request->validated('locale') ?? $conversation->locale);
        $prompt = (string) $request->validated('prompt');

        try {
            $this->command->assertCanRun($user, $tenantId);
        } catch (ExternalAiNotAllowedException $exception) {
            return $this->problemDetailsResponse('External AI not allowed', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        }

        if ($this->wantsStream($request)) {
            return $this->stream($request, $tenantId, $prompt, $locale, $context, $conversation);
        }

        try {
            $message = $this->command->execute($user, $tenantId, $prompt, $locale, $context, $conversation, preflighted: true);
        } catch (CopilotProviderException $exception) {
            report($exception);

            return $this->problemDetailsResponse('Language model unavailable', 'The language model provider could not answer. Try again later.', Response::HTTP_BAD_GATEWAY);
        }

        return $this->successResponse(new CopilotMessageResource($message));
    }

    private function wantsStream(Request $request): bool
    {
        return str_contains((string) $request->header('Accept'), 'text/event-stream');
    }

    private function stream(Request $request, string $tenantId, string $prompt, string $locale, PromptContext $context, CopilotConversation $conversation): StreamedResponse
    {
        $user = $request->user();

        return response()->stream(function () use ($user, $tenantId, $prompt, $locale, $context, $conversation): void {
            $emit = static function (string $event, array $data): void {
                echo "event: {$event}\n";
                echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            };

            try {
                $this->command->execute($user, $tenantId, $prompt, $locale, $context, $conversation, $emit, preflighted: true);
            } catch (Throwable $exception) {
                report($exception);
                $emit('error', ['message' => $exception instanceof CopilotProviderException ? 'The language model provider could not answer.' : 'The Copilot could not complete the request.']);
            }
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
