<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\Access\SaveInvitationRequest;
use App\Http\Resources\Access\InvitationResource;
use App\Http\Responses\ApiResponse;
use App\Models\Access\TenantInvitation;
use App\Services\Tenancy\AccessInvitations;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * E-mail invitations of the tenant: list, send, send again and revoke.
 */
class InvitationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AccessInvitations $invitations,
    ) {}

    #[OA\Get(path: '/api/v1/settings/invitations', operationId: 'listTenantInvitations', description: 'Every invitation of the active tenant with its status: pending, accepted, revoked or expired.', summary: 'List invitations', security: [['bearerAuth' => []]], tags: ['Settings', 'Invitations'], responses: [
        new OA\Response(response: 200, description: 'Invitations retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function index(): JsonResponse
    {
        return $this->successResponse(data: InvitationResource::collection($this->invitations->list($this->tenantContext->getTenant()))->resolve(), message: 'Invitations retrieved');
    }

    #[OA\Post(path: '/api/v1/settings/invitations', operationId: 'inviteTenantUser', description: 'Sends an invitation e-mail with a link valid for a few days. An address with an open invitation gets it renewed. The plan must have room for another person.', summary: 'Invite a person', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['email'], properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'role', type: 'string', example: 'member'),
        new OA\Property(property: 'custom_role_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'modules', type: 'object', example: ['crm' => 'read'], additionalProperties: new OA\AdditionalProperties(type: 'string', enum: ['default', 'blocked', 'read', 'write'])),
        new OA\Property(property: 'team_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
    ])), tags: ['Settings', 'Invitations'], responses: [
        new OA\Response(response: 201, description: 'Invitation sent', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 409, description: 'Already a member', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error or plan limit', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function store(SaveInvitationRequest $request): JsonResponse
    {
        try {
            $invitation = $this->invitations->invite($this->tenantContext->getTenant(), $request->user(), $request->validated());
        } catch (DomainException $exception) {
            return $this->problemDetailsResponse(title: 'Already A Member', detail: $exception->getMessage(), status: Response::HTTP_CONFLICT);
        }

        return $this->successResponse(data: InvitationResource::make($invitation->load('inviter'))->resolve(), message: 'Invitation sent', statusCode: Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/settings/invitations/{id}/resend', operationId: 'resendTenantInvitation', description: 'Sends the invitation again with a new link and a new expiry; the previous link stops working.', summary: 'Resend an invitation', security: [['bearerAuth' => []]], tags: ['Settings', 'Invitations'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Invitation sent again', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 409, description: 'Invitation accepted or revoked', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function resend(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $invitation = TenantInvitation::query()->where('tenant_id', $tenant->id)->findOrFail($id);

        try {
            $invitation = $this->invitations->resend($tenant, $request->user(), $invitation);
        } catch (DomainException $exception) {
            return $this->problemDetailsResponse(title: 'Invitation Closed', detail: $exception->getMessage(), status: Response::HTTP_CONFLICT);
        }

        return $this->successResponse(data: InvitationResource::make($invitation->load('inviter'))->resolve(), message: 'Invitation sent again');
    }

    #[OA\Delete(path: '/api/v1/settings/invitations/{id}', operationId: 'revokeTenantInvitation', description: 'Revokes an invitation: its link stops working. An accepted invitation cannot be revoked (remove the member instead).', summary: 'Revoke an invitation', security: [['bearerAuth' => []]], tags: ['Settings', 'Invitations'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Invitation revoked', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 409, description: 'Invitation already accepted', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function destroy(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $invitation = TenantInvitation::query()->where('tenant_id', $tenant->id)->findOrFail($id);

        try {
            $this->invitations->revoke($tenant, $invitation);
        } catch (DomainException $exception) {
            return $this->problemDetailsResponse(title: 'Invitation Closed', detail: $exception->getMessage(), status: Response::HTTP_CONFLICT);
        }

        return $this->successResponse(message: 'Invitation revoked');
    }
}
