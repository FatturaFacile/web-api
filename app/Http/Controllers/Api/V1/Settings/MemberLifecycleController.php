<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Api\V1\Settings\Concerns\GuardsMembershipChanges;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\Access\ImportUsersRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Tenancy\AccessAudit;
use App\Services\Tenancy\AccessPlanLimits;
use App\Services\Tenancy\AccessUserImport;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantMembershipService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Suspending and reactivating a member of the tenant, and adding many people from a CSV file.
 */
class MemberLifecycleController extends Controller
{
    use ApiResponse, GuardsMembershipChanges;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantMembershipService $memberships,
        private readonly AccessPlanLimits $limits,
        private readonly AccessAudit $audit,
        private readonly AccessUserImport $import,
    ) {}

    #[OA\Post(path: '/api/v1/settings/users/{userId}/suspend', operationId: 'suspendTenantUser', description: 'Closes the access of a member to the active tenant without removing the membership: role, module levels and history stay, and the accesses opened through the SSO of the tenant end.', summary: 'Suspend a member', security: [['bearerAuth' => []]], tags: ['Settings', 'Users'], parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Member suspended', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not a member', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 409, description: 'Own membership or last owner', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function suspend(Request $request, string $userId): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $user = $tenant->users()->where('users.id', $userId)->firstOrFail();

        if ($refusal = $this->refuseSelfOrOwnerChange($request, $user)) {
            return $refusal;
        }

        try {
            $this->memberships->suspend($tenant, $user);
        } catch (DomainException $exception) {
            return $this->problemDetailsResponse(title: 'Last Owner', detail: $exception->getMessage(), status: Response::HTTP_CONFLICT);
        }

        $this->audit->record('access.member.suspended', (string) $tenant->id, User::class, (string) $user->id, ['suspended' => false], ['suspended' => true]);

        return $this->successResponse(data: $this->memberships->member($tenant, $userId), message: 'Member suspended');
    }

    #[OA\Post(path: '/api/v1/settings/users/{userId}/reactivate', operationId: 'reactivateTenantUser', description: 'Gives a suspended member the access back, with the role and the module levels they had. The plan must have room for the active member.', summary: 'Reactivate a member', security: [['bearerAuth' => []]], tags: ['Settings', 'Users'], parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Member reactivated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not a member', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'The plan has no room for another active member', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function reactivate(Request $request, string $userId): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $user = $tenant->users()->where('users.id', $userId)->firstOrFail();

        if ($refusal = $this->refuseSelfOrOwnerChange($request, $user)) {
            return $refusal;
        }

        if ($user->pivot->suspended_at !== null) {
            $this->limits->assertRoomForUsers($tenant);
            $this->memberships->reactivate($tenant, $user);
            $this->audit->record('access.member.reactivated', (string) $tenant->id, User::class, (string) $user->id, ['suspended' => true], ['suspended' => false]);
        }

        return $this->successResponse(data: $this->memberships->member($tenant, $userId), message: 'Member reactivated');
    }

    #[OA\Post(path: '/api/v1/settings/users/import', operationId: 'importTenantUsers', description: 'Adds many people from a CSV file with the columns email;name;role;team (semicolon or comma separated, teams separated by |). A registered address becomes a member, an unknown one receives an invitation. With dry_run the file is only checked.', summary: 'Import users from CSV', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['file'], properties: [
        new OA\Property(property: 'file', type: 'string', format: 'binary'),
        new OA\Property(property: 'dry_run', type: 'boolean'),
        new OA\Property(property: 'default_role', type: 'string', example: 'member'),
    ]))), tags: ['Settings', 'Users'], responses: [
        new OA\Response(response: 200, description: 'The report of every row', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error or unusable file', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function import(ImportUsersRequest $request): JsonResponse
    {
        $contents = (string) file_get_contents($request->file('file')->getRealPath());

        try {
            $report = $this->import->run($this->tenantContext->getTenant(), $request->user(), $contents, $request->dryRun(), $request->defaultRole());
        } catch (DomainException $exception) {
            return $this->problemDetailsResponse(
                title: 'Unusable File',
                detail: $exception->getMessage(),
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                invalidParams: [['name' => 'file', 'reason' => $exception->getMessage()]],
            );
        }

        return $this->successResponse(data: $report, message: $request->dryRun() ? 'Import checked' : 'Import completed');
    }
}
