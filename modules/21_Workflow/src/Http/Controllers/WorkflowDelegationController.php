<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Modules\Workflow\Actions\CreateDelegationAction;
use Modules\Workflow\DTOs\CreateDelegationDTO;
use Modules\Workflow\Http\Requests\CreateDelegationRequest;
use Modules\Workflow\Models\WorkflowDelegation;

final class WorkflowDelegationController extends Controller
{
    #[EnterpriseEndpoint(summary: 'List active user delegations', tags: ['Workflow Engine'])]
    public function index(): JsonResponse
    {
        $userId = auth()->id();

        $delegations = WorkflowDelegation::where('delegator_user_id', $userId)
            ->orWhere('delegate_user_id', $userId)
            ->with(['delegator', 'delegate'])
            ->orderBy('starts_at', 'desc')
            ->get();

        return response()->json($delegations);
    }

    #[EnterpriseEndpoint(summary: 'Create a new out-of-office workflow delegation', tags: ['Workflow Engine'])]
    public function store(CreateDelegationRequest $request, CreateDelegationAction $action): JsonResponse
    {
        $dto = new CreateDelegationDTO(
            delegatorUserId: (string) auth()->id(),
            delegateUserId: $request->string('delegate_user_id')->value(),
            startsAt: Carbon::parse($request->string('starts_at')->value()),
            endsAt: Carbon::parse($request->string('ends_at')->value()),
            scope: $request->string('scope', 'all')->value(),
            reason: $request->input('reason')
        );

        $delegation = $action->execute($dto);

        return response()->json($delegation, 201);
    }

    #[EnterpriseEndpoint(summary: 'Revoke an existing workflow delegation', tags: ['Workflow Engine'])]
    public function destroy(string $id): JsonResponse
    {
        $delegation = WorkflowDelegation::where('delegator_user_id', auth()->id())->findOrFail($id);
        $delegation->update(['is_active' => false]);

        return response()->json(['message' => 'Delegation successfully revoked.']);
    }
}
