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
use Symfony\Component\HttpFoundation\Response;

class MobileApprovalController extends Controller
{
    use ApiResponse, ResolvesMobileContext;

    /** Manager inbox: pending steps assigned to the user (or to one of their roles). */
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
