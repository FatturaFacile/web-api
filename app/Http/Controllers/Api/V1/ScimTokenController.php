<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ScimToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\Scim\ScimTokens;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The SCIM 2.0 bearer token of the active tenant: status, rotation (which also creates the first one) and revocation.
 * The plain token is returned once, at creation; only its hash is stored. Reserved to owners and administrators.
 */
class ScimTokenController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ScimTokens $tokens,
        private readonly RegistrationAudit $audit,
    ) {}

    #[OA\Get(
        path: '/api/v1/settings/security/scim',
        operationId: 'getScimToken',
        description: 'Whether the tenant has a usable SCIM token, its last four characters, its last use and expiry. The token itself is never returned again.',
        summary: 'SCIM token status',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        responses: [
            new OA\Response(response: 200, description: 'Status', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ScimToken')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(): JsonResponse
    {
        return $this->successResponse(data: $this->present($this->tokens->current((string) $this->activeTenant()->id)));
    }

    #[OA\Post(
        path: '/api/v1/settings/security/scim/token',
        operationId: 'rotateScimToken',
        description: 'Creates a new SCIM token and revokes the previous one at once. The plain token is in this response only.',
        summary: 'Create or rotate the SCIM token',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'expires_in_days', type: 'integer', minimum: 1, maximum: 730, nullable: true)])),
        responses: [
            new OA\Response(response: 201, description: 'The new token, shown once', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ScimToken')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function rotate(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $validated = $request->validate(['expires_in_days' => ['nullable', 'integer', 'min:1', 'max:730']]);
        $tenantId = (string) $this->activeTenant()->id;

        [$token, $plain] = $this->tokens->rotate($tenantId, (string) $admin->id, isset($validated['expires_in_days']) ? (int) $validated['expires_in_days'] : null);
        $this->audit->record(RegistrationAudit::SCIM_TOKEN_ROTATED, (string) $admin->id, ['scim_token' => $token->id], $tenantId);

        return $this->successResponse(data: $this->present($token) + ['token' => $plain], message: __('enterprise_auth.messages.scim_token_rotated'), statusCode: Response::HTTP_CREATED);
    }

    #[OA\Delete(
        path: '/api/v1/settings/security/scim/token',
        operationId: 'revokeScimToken',
        description: 'Revokes the SCIM token of the tenant: the identity provider can no longer provision.',
        summary: 'Revoke the SCIM token',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        responses: [
            new OA\Response(response: 200, description: 'Revoked', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function revoke(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $tenantId = (string) $this->activeTenant()->id;
        $revoked = $this->tokens->revoke($tenantId);

        if ($revoked > 0) {
            $this->audit->record(RegistrationAudit::SCIM_TOKEN_REVOKED, (string) $admin->id, ['revoked' => $revoked], $tenantId);
        }

        return $this->successResponse(message: __('enterprise_auth.messages.scim_token_revoked'));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(?ScimToken $token): array
    {
        return [
            'configured' => $token !== null,
            'token_hint' => $token?->token_hint,
            'created_at' => $token?->created_at?->toIso8601String(),
            'last_used_at' => $token?->last_used_at?->toIso8601String(),
            'expires_at' => $token?->expires_at?->toIso8601String(),
        ];
    }

    private function activeTenant(): Tenant
    {
        $tenant = $this->tenantContext->getTenant();
        abort_if($tenant === null, Response::HTTP_BAD_REQUEST, 'Missing tenant context.');

        return $tenant;
    }
}
