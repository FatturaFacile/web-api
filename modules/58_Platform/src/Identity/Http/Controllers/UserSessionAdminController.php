<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\UserSessions;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Platform\Identity\Domain\Enums\SessionRevocationReason;
use Modules\Platform\Identity\Domain\Events\SessionsRevoked;
use Symfony\Component\HttpFoundation\Response;

/** An administrator ends every token and browser session of a member: the next request of that member answers 401. */
final class UserSessionAdminController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly UserSessions $sessions, private readonly TenantContext $tenantContext) {}

    public function revoke(Request $request, string $user): JsonResponse
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $target = User::query()->whereHas('tenants', fn ($q) => $q->where('tenants.id', $tenantId))->findOrFail($user);

        if ($target->is($request->user())) {
            return $this->problemDetailsResponse('Own Sessions', 'Use the profile to end your own sessions.', Response::HTTP_CONFLICT);
        }

        $revoked = $this->sessions->revokeAll($target);
        event(new SessionsRevoked($tenantId, (string) $target->id, SessionRevocationReason::AdminRevoked->value, (string) $request->user()?->getAuthIdentifier()));

        return $this->successResponse($revoked, 'Sessions revoked');
    }
}
