<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Actions\Mobile\ExecuteQuickApprovalFromPushAction;
use App\Http\Controllers\Api\V1\Mobile\Concerns\ResolvesMobileContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Mobile\RoleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Workflow\Models\WorkflowInstanceStep;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class MobileApprovalController extends Controller
{
    use ApiResponse, ResolvesMobileContext;

    /** Manager inbox: pending steps assigned to the user (or to one of their roles). */
    #[OA\Get(
        path: '/api/v1/mobile/approvals',
        operationId: 'listMobileApprovals',
        description: 'Pending workflow steps assigned to the user or to one of their roles, ordered by due date.',
        summary: 'Approval inbox',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        parameters: [new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Page size (default 25)', schema: new OA\Schema(type: 'integer', default: 25)), new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Pending approval steps', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function inbox(Request $request): JsonResponse
    {
        $user = $request->user();

        $steps = WorkflowInstanceStep::where('status', 'pending')
            ->where(fn ($q) => $q->where('assigned_user_id', $user->id)->orWhereIn('assigned_role', app(RoleResolver::class)->roles($user)))
            ->with(['instance', 'node'])
            ->orderBy('due_at')
            ->paginate((int) $request->query('per_page', 25));

        return $this->successResponse($steps->items(), 'Pending approvals', meta: [
            'current_page' => $steps->currentPage(),
            'last_page' => $steps->lastPage(),
            'total' => $steps->total(),
        ]);
    }

    /** Approve / reject from the push banner or the swipe gesture. */
    #[OA\Post(
        path: '/api/v1/mobile/approvals/{stepId}/decision',
        operationId: 'decideMobileApproval',
        description: 'Approve or reject a pending step from a push banner or swipe gesture. Requires a registered device; an Idempotency-Key header (or idempotency_key field) makes retries safe.',
        summary: 'Decide approval step',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        parameters: [new OA\Parameter(name: 'stepId', in: 'path', required: true, description: 'Identifier of the workflow step', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Retry-safe key (alternative to the body field)', schema: new OA\Schema(type: 'string', maxLength: 100))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['decision'], properties: [new OA\Property(property: 'decision', type: 'string', enum: ['approved', 'rejected']), new OA\Property(property: 'comment', type: 'string', maxLength: 1000, nullable: true), new OA\Property(property: 'idempotency_key', type: 'string', maxLength: 100, nullable: true)])),
        responses: [
            new OA\Response(response: 200, description: 'Decision recorded', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'step_id', type: 'string', format: 'uuid'), new OA\Property(property: 'status', type: 'string')]), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Action not possible in the current state', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 428, description: 'Device not registered', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
        ]
    )]
    public function decide(Request $request, string $stepId, ExecuteQuickApprovalFromPushAction $action): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $device = $this->currentDevice($request);
        if ($device === null) {
            return $this->problemDetailsResponse('Device Not Registered', 'Quick approvals require a registered device.', Response::HTTP_PRECONDITION_REQUIRED);
        }

        try {
            $step = $action->execute(
                $request->user(),
                $device,
                $stepId,
                $data['decision'],
                $data['comment'] ?? null,
                $data['idempotency_key'] ?? $request->header('Idempotency-Key'),
                $request->ip(),
            );
        } catch (InvalidArgumentException $e) {
            return $this->problemDetailsResponse('Approval Not Possible', $e->getMessage(), Response::HTTP_CONFLICT);
        }

        return $this->successResponse(['step_id' => $step->id, 'status' => $step->status], 'Decision recorded');
    }
}
