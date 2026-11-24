<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Platform\Identity\Application\AccessReviewReportQuery;
use Modules\Platform\Identity\Application\ImpersonationService;
use Modules\Platform\Identity\Http\Requests\StartImpersonationRequest;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/** Support impersonation of a member (owner only, 30 minutes at most) and the access review report. */
final class ImpersonationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ImpersonationService $impersonation,
        private readonly AccessReviewReportQuery $review,
        private readonly TenantContext $tenantContext,
    ) {}

    #[OA\Post(path: '/api/v1/settings/users/{user}/impersonate', operationId: 'startImpersonation', description: 'Opens a time-limited, audited session as the user.', summary: 'Start impersonating a user', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Identity'], parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'OK')])]
    public function start(StartImpersonationRequest $request, string $user): JsonResponse
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $target = $this->member($tenantId, $user);

        try {
            $data = $this->impersonation->start(
                $request->user(),
                $target,
                $tenantId,
                (string) $request->validated('consent_reference'),
                (int) $request->validated('ttl_minutes', ImpersonationService::MAX_TTL_MINUTES),
            );
        } catch (DomainException $e) {
            return $this->problemDetailsResponse('Impersonation refused', $e->getMessage(), Response::HTTP_FORBIDDEN);
        }

        return $this->successResponse($data, 'Impersonation started', Response::HTTP_CREATED);
    }

    #[OA\Delete(path: '/api/v1/settings/users/{user}/impersonate', operationId: 'endImpersonation', description: 'Closes the impersonation session.', summary: 'Stop impersonating a user', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Identity'], parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'OK')])]
    public function end(Request $request, string $user): JsonResponse
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $closed = $this->impersonation->end($request->user(), $this->member($tenantId, $user), $tenantId);

        return $this->successResponse(['closed' => $closed], 'Impersonation ended');
    }

    #[OA\Get(path: '/api/v1/settings/access-review', operationId: 'getAccessReview', description: 'The users with their roles and last activity, for the periodic access review.', summary: 'Access review', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Identity'], responses: [new OA\Response(response: 200, description: 'OK')])]
    public function accessReview(): JsonResponse
    {
        return $this->successResponse($this->review->handle((string) $this->tenantContext->getTenantId()));
    }

    private function member(string $tenantId, string $user): User
    {
        return User::query()->whereHas('tenants', fn ($q) => $q->where('tenants.id', $tenantId))->findOrFail($user);
    }
}
