<?php

declare(strict_types=1);

namespace Modules\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Assistant\Http\Requests\AssistantFeedbackRequest;
use Modules\Assistant\Http\Resources\AssistantLogResource;
use Modules\Assistant\Http\Resources\AssistantThreadResource;
use Modules\Assistant\Models\AssistantQuestionLog;
use Modules\Assistant\Models\AssistantThread;
use OpenApi\Attributes as OA;

/**
 * Conversations of the caller with the assistant, and the feedback on single answers. A user only ever sees their own threads and answers.
 */
final class AssistantThreadController extends Controller
{
    use ApiResponse;

    private const int ThreadLimit = 50;

    /**
     * List own threads.
     */
    #[OA\Get(
        path: '/api/v1/assistant/threads',
        operationId: 'listAssistantThreads',
        description: 'The latest conversations of the caller, most recently active first (at most 50).',
        summary: 'List own conversations',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Assistant'],
        responses: [
            new OA\Response(response: 200, description: 'Threads', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssistantThreadResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $threads = AssistantThread::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(self::ThreadLimit)
            ->get();

        return $this->successResponse(AssistantThreadResource::collection($threads));
    }

    /**
     * Show a thread with its questions.
     */
    #[OA\Get(
        path: '/api/v1/assistant/threads/{id}',
        operationId: 'getAssistantThread',
        description: 'A conversation with its questions, oldest first: what was asked, how it resolved and the feedback. The answer rows are not stored, ask again to see the current figures.',
        summary: 'Show a conversation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Assistant'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The thread', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssistantThreadResource')])),
            new OA\Response(response: 404, description: 'Not found, or not owned by the caller', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        return $this->successResponse(new AssistantThreadResource($this->ownThread($request, $id)->load('messages')));
    }

    /**
     * Delete a thread.
     */
    #[OA\Delete(
        path: '/api/v1/assistant/threads/{id}',
        operationId: 'deleteAssistantThread',
        description: 'Removes the conversation from the list of the caller. The question log of the company is kept for the audit.',
        summary: 'Delete a conversation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Assistant'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found, or not owned by the caller', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->ownThread($request, $id)->delete();

        return $this->successResponse(null, 'Conversation deleted');
    }

    /**
     * Rate an answer.
     */
    #[OA\Post(
        path: '/api/v1/assistant/logs/{id}/feedback',
        operationId: 'rateAssistantAnswer',
        description: 'The caller rates one of their own answers as helpful or not, with an optional comment. A new rating replaces the previous one. Owners and administrators read the ratings in the question log.',
        summary: 'Rate an answer',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssistantFeedbackRequest')),
        tags: ['Assistant'],
        parameters: [new OA\Parameter(name: 'id', description: 'The log_id of the answer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Rated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssistantLogResource')])),
            new OA\Response(response: 404, description: 'Not found, or not owned by the caller', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function feedback(AssistantFeedbackRequest $request, string $id): JsonResponse
    {
        $log = AssistantQuestionLog::query()->where('user_id', $request->user()->id)->findOrFail($id);
        $log->update([
            'feedback' => $request->validated('rating'),
            'feedback_comment' => $request->validated('comment'),
            'feedback_at' => now(),
        ]);

        return $this->successResponse(new AssistantLogResource($log->refresh()), 'Feedback saved');
    }

    private function ownThread(Request $request, string $id): AssistantThread
    {
        return AssistantThread::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }
}
