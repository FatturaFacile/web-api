<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Exceptions\PlanLimitExceededException;
use App\Exceptions\SodConflictException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Access\TenantInvitation;
use App\Models\Tenant;
use App\Services\Access\InvitationService;
use App\Services\Access\UserImportService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantMembershipService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Email invitations (managed by owners and administrators, accepted by the invited person without an account in
 * the tenant yet) and the bulk import of users from CSV.
 */
class InvitationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly InvitationService $invitations,
        private readonly UserImportService $import,
        private readonly TenantMembershipService $memberships,
    ) {}

    #[OA\Get(path: '/api/v1/settings/invitations', operationId: 'listTenantInvitations', summary: 'List invitations', security: [['bearerAuth' => []]], tags: ['Settings', 'Users'], responses: [new OA\Response(response: 200, description: 'Invitations', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function index(): JsonResponse
    {
        return $this->successResponse(data: $this->invitations->describeAll($this->tenant()), message: 'Invitations retrieved');
    }

    #[OA\Post(path: '/api/v1/settings/invitations', operationId: 'inviteTenantUser', summary: 'Invite a person by email', description: 'Sends an email with a link to accept; the role, custom role, module levels and teams are applied on acceptance.', security: [['bearerAuth' => []]], tags: ['Settings', 'Users'], responses: [new OA\Response(response: 201, description: 'Invitation sent', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 409, description: 'Already a member or plan limit reached', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))])]
    public function store(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['sometimes', 'string', Rule::in(array_diff($this->memberships->roles(), ['owner']))],
            'custom_role_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('tenant_roles', 'id')->where('tenant_id', $tenant->id)->whereNull('deleted_at')],
            'modules' => ['sometimes', 'array:'.implode(',', $this->memberships->modules())],
            'modules.*' => ['string', Rule::in($this->memberships->levels())],
            'team_ids' => ['sometimes', 'array'],
            'team_ids.*' => ['uuid', Rule::exists('tenant_teams', 'id')->where('tenant_id', $tenant->id)->whereNull('deleted_at')],
        ]);

        try {
            $invitation = $this->invitations->invite($tenant, $data['email'], $data['role'] ?? null, $data['custom_role_id'] ?? null, $data['modules'] ?? null, $data['team_ids'] ?? null, $request->user());
        } catch (PlanLimitExceededException $exception) {
            return $this->problemDetailsResponse(
                title: __('access.errors.plan_limit_title'),
                detail: __('access.errors.plan_limit_detail', ['max' => $exception->max, 'limit' => $exception->limit]),
                status: Response::HTTP_CONFLICT,
            );
        } catch (DomainException $exception) {
            return $this->problemDetailsResponse(title: 'Already A Member', detail: $exception->getMessage(), status: Response::HTTP_CONFLICT);
        }

        return $this->successResponse(data: collect($this->invitations->describeAll($tenant))->firstWhere('id', $invitation->id), message: 'Invitation sent', statusCode: Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/settings/invitations/{id}/resend', operationId: 'resendTenantInvitation', summary: 'Send an invitation again with a new link', security: [['bearerAuth' => []]], tags: ['Settings', 'Users'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Invitation sent again', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function resend(Request $request, string $id): JsonResponse
    {
        try {
            $this->invitations->resend($this->tenant(), $this->find($id), $request->user());
        } catch (DomainException $exception) {
            return $this->problemDetailsResponse(title: __('access.errors.invitation_invalid_title'), detail: $exception->getMessage(), status: Response::HTTP_CONFLICT);
        }

        return $this->successResponse(data: collect($this->invitations->describeAll($this->tenant()))->firstWhere('id', $id), message: 'Invitation sent again');
    }

    #[OA\Delete(path: '/api/v1/settings/invitations/{id}', operationId: 'revokeTenantInvitation', summary: 'Revoke an invitation', security: [['bearerAuth' => []]], tags: ['Settings', 'Users'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Invitation revoked', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function destroy(string $id): JsonResponse
    {
        $this->invitations->revoke($this->tenant(), $this->find($id));

        return $this->successResponse(message: 'Invitation revoked');
    }

    #[OA\Post(path: '/api/v1/settings/users/import', operationId: 'importTenantUsers', summary: 'Import users from CSV', description: 'CSV with the header columns email (required), name, role, team. Existing accounts become members, other addresses are invited. dry_run=1 only checks the file.', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['file'], properties: [new OA\Property(property: 'file', type: 'string', format: 'binary'), new OA\Property(property: 'dry_run', type: 'boolean'), new OA\Property(property: 'default_role', type: 'string')]))), tags: ['Settings', 'Users'], responses: [new OA\Response(response: 200, description: 'Import report', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 422, description: 'File not valid', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))])]
    public function importUsers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'dry_run' => ['sometimes', 'boolean'],
            'default_role' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        try {
            $report = $this->import->import($this->tenant(), $request->file('file')->getRealPath(), $request->boolean('dry_run'), $data['default_role'] ?? null, $request->user());
        } catch (DomainException $exception) {
            return $this->problemDetailsResponse(title: __('access.errors.import_invalid_title'), detail: $exception->getMessage(), status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->successResponse(data: $report, message: $report['dry_run'] ? 'Import checked' : 'Users imported');
    }

    #[OA\Get(path: '/api/v1/auth/invitations/{token}', operationId: 'previewInvitation', summary: 'Show an invitation to the invited person', tags: ['Auth'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Invitation', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 404, description: 'Invalid, expired or used link', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function preview(string $token): JsonResponse
    {
        $preview = $this->invitations->preview($token);

        if ($preview === null) {
            return $this->problemDetailsResponse(title: __('access.errors.invitation_invalid_title'), detail: 'The invitation is not valid any more.', status: Response::HTTP_NOT_FOUND);
        }

        return $this->successResponse(data: $preview, message: 'Invitation retrieved');
    }

    #[OA\Post(path: '/api/v1/auth/invitations/{token}/accept', operationId: 'acceptInvitation', summary: 'Accept an invitation', description: 'A new address sends name and password and gets an account; an address with an account must send the bearer token of that account.', tags: ['Auth'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Invitation accepted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 409, description: 'Invalid link or sign-in needed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function accept(Request $request, string $token): JsonResponse
    {
        $preview = $this->invitations->preview($token);

        if ($preview === null) {
            return $this->problemDetailsResponse(title: __('access.errors.invitation_invalid_title'), detail: 'The invitation is not valid any more.', status: Response::HTTP_NOT_FOUND);
        }

        $rules = $preview['has_account']
            ? []
            : ['name' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'confirmed', Password::defaults()]];

        $data = $request->validate($rules);

        try {
            $user = $this->invitations->accept($token, $request->user('sanctum'), $data['name'] ?? null, $data['password'] ?? null);
        } catch (SodConflictException $exception) {
            return $this->problemDetailsResponse(title: 'Segregation of Duties Conflict', detail: $exception->getMessage(), status: Response::HTTP_CONFLICT);
        } catch (DomainException $exception) {
            return $this->problemDetailsResponse(title: __('access.errors.invitation_invalid_title'), detail: $exception->getMessage(), status: Response::HTTP_CONFLICT);
        }

        return $this->successResponse(data: ['email' => $user->email, 'tenant_name' => $preview['tenant_name']], message: 'Invitation accepted');
    }

    private function tenant(): Tenant
    {
        return $this->tenantContext->getTenant();
    }

    private function find(string $id): TenantInvitation
    {
        return TenantInvitation::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant()->id)->findOrFail($id);
    }
}
