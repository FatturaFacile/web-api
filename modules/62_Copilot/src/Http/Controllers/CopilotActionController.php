<?php

declare(strict_types=1);

namespace Modules\Copilot\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\Copilot\Commands\ConfirmAndExecuteCopilotActionCommand;
use Modules\Copilot\Commands\RejectCopilotActionCommand;
use Modules\Copilot\Enums\ActionStatus;
use Modules\Copilot\Http\Requests\ConfirmCopilotActionRequest;
use Modules\Copilot\Http\Requests\RejectCopilotActionRequest;
use Modules\Copilot\Http\Resources\CopilotMessageResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Human-in-the-loop: confirm or reject an action the Copilot proposed.
 */
final class CopilotActionController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Post(
        path: '/api/v1/copilot/actions/{messageId}/confirm',
        operationId: 'confirmCopilotAction',
        description: 'Executes the pending action of a message of an own conversation, with the permission the action needs today. `overrides` changes only the fields the card lists in `editable_fields` (dotted paths such as items.0.quantity). Confirming an action that already ran returns it unchanged, so a retry never executes twice; an action that failed answers 422 and stays failed.',
        summary: 'Confirm and execute a Copilot action',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/ConfirmCopilotActionRequest')),
        tags: ['Copilot'],
        parameters: [new OA\Parameter(name: 'messageId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The message with the executed action and its result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CopilotMessageResource')])),
            new OA\Response(response: 403, description: 'The caller lacks the permission of the action', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'No such action in an own conversation', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The action was rejected, failed, or is being handled', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid overrides, or the action failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function confirm(ConfirmCopilotActionRequest $request, string $messageId, ConfirmAndExecuteCopilotActionCommand $command): JsonResponse
    {
        $message = $command->execute($request->user(), (string) $this->tenantContext->getTenantId(), $messageId, (array) ($request->validated('overrides') ?? []));

        if ($message->action_status === ActionStatus::Failed) {
            return $this->problemDetailsResponse('Action failed', (string) ($message->proposed_action_json['error'] ?? 'The action failed.'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->successResponse(new CopilotMessageResource($message), 'Action executed');
    }

    #[OA\Post(
        path: '/api/v1/copilot/actions/{messageId}/reject',
        operationId: 'rejectCopilotAction',
        description: 'Cancels the pending action of a message of an own conversation. Rejecting twice is harmless; an action that already ran cannot be rejected.',
        summary: 'Reject a Copilot action',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/RejectCopilotActionRequest')),
        tags: ['Copilot'],
        parameters: [new OA\Parameter(name: 'messageId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The message with the rejected action', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CopilotMessageResource')])),
            new OA\Response(response: 404, description: 'No such action in an own conversation', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The action already ran or failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function reject(RejectCopilotActionRequest $request, string $messageId, RejectCopilotActionCommand $command): JsonResponse
    {
        $message = $command->execute($request->user(), (string) $this->tenantContext->getTenantId(), $messageId, $request->validated('reason'));

        return $this->successResponse(new CopilotMessageResource($message), 'Action rejected');
    }
}
