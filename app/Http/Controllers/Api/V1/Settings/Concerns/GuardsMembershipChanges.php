<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings\Concerns;

use App\Models\Pivots\TenantMembership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two rules every change of a membership follows. Needs `$this->tenantContext` and the ApiResponse trait.
 */
trait GuardsMembershipChanges
{
    /**
     * Nobody changes their own membership (a lock-out), and only an owner touches the membership of an owner.
     *
     * @param  User&object{pivot: TenantMembership}  $member  a user loaded through the members of the tenant
     */
    private function refuseSelfOrOwnerChange(Request $request, User $member): ?JsonResponse
    {
        if ($member->is($request->user())) {
            return $this->problemDetailsResponse(title: 'Own Membership', detail: 'You cannot change your own membership.', status: Response::HTTP_CONFLICT);
        }

        if ($member->pivot->role === 'owner' && ! $this->actorIsOwner($request)) {
            return $this->problemDetailsResponse(title: 'Owner Role Reserved', detail: 'Only an owner can change the membership of an owner.', status: Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function actorIsOwner(Request $request): bool
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor->is_platform_admin || $actor->getRoleInTenant((string) $this->tenantContext->getTenantId()) === 'owner';
    }
}
