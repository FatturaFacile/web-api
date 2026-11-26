<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UnlockMemberRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\Security\LoginLockout;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantMembershipService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Owners and administrators lift the lockout of a member of their tenant (failed attempts, lock and the streak of the
 * progressive lockout), see LoginLockout. A user who is not a member of the tenant is a 404, so nothing leaks across tenants.
 */
class MemberUnlockController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LoginLockout $lockout,
        private readonly TenantMembershipService $memberships,
    ) {}

    #[OA\Post(
        path: '/api/v1/settings/users/{userId}/unlock',
        operationId: 'unlockTenantUser',
        description: 'Clears the failed sign-in attempts, the lock and the lockout streak of a member of the active tenant. The event is written to the audit ledger (LOGIN_UNLOCKED) and the member is told by email. A user who is not a member of the tenant is a 404.',
        summary: 'Unlock a member',
        security: [['bearerAuth' => []]],
        tags: ['Settings', 'Users'],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The member, with locked, locked_until and failed_attempts', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not a member of this tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function unlock(UnlockMemberRequest $request, string $userId): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $tenant = $this->tenantContext->getTenant();
        abort_if($tenant === null, 400, 'Missing tenant context.');

        /** @var User $member */
        $member = $tenant->users()->where('users.id', $userId)->firstOrFail();

        $this->lockout->unlock($member, $admin, (string) $tenant->id);

        return $this->successResponse(data: $this->memberships->member($tenant, $userId), message: __('advanced_auth.messages.member_unlocked'));
    }
}
