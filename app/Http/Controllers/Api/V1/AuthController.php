<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Auth\AuthProblem;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\LoginResponder;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\Security\LoginLockout;
use App\Services\Auth\Security\PasswordPolicy;
use App\Services\Auth\Security\TenantSecurityPolicies;
use App\Services\Auth\Sso\SsoLogout;
use App\Services\Auth\TokenMode;
use App\Services\Auth\TwoFactorAuthentication;
use App\Services\Tenancy\TenantContext;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TwoFactorAuthentication $twoFactor,
        private readonly RegistrationAudit $audit,
        private readonly LoginResponder $responder,
        private readonly LoginLockout $lockout,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly TenantSecurityPolicies $policies,
        private readonly TokenMode $tokenModes,
        private readonly SsoLogout $ssoLogout,
    ) {}

    /**
     * A hash of a random password with the current hashing options, made once per process: checking it costs the same
     * as checking a real one.
     */
    private static function timingDummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(40));
    }

    /**
     * Authenticate user credentials and issue a Sanctum Bearer token.
     */
    #[OA\Post(
        path: '/api/v1/auth/login',
        operationId: 'login',
        description: 'Cookie authentication (default and only mode unless the token mode is enabled on the server): a first-party SPA served over https from a Sanctum stateful domain first calls GET /sanctum/csrf-cookie, then logs in with credentials included and the X-XSRF-TOKEN header; the response sets the HttpOnly + Secure session cookie (the session id is regenerated at login) and carries no token (auth_mode=cookie). A request that does not come from a stateful origin is a 400. Token mode (server setting auth.token_mode.enabled, off by default): a client that sends token_mode=true receives an expiring Bearer token (auth_mode=token, token, expires_at); with the setting off token_mode=true is a 422. With two-factor authentication the response carries a challenge_token instead, exchanged at /auth/two-factor-challenge in the same mode.',
        summary: 'User login',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'secret123'),
                    new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', nullable: true, example: '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'),
                    new OA\Property(property: 'token_mode', description: 'Ask for a Bearer token instead of the cookie session. Accepted only when the server enables the token mode (auth.token_mode.enabled); otherwise a 422.', type: 'boolean', default: false),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Authenticated successfully (cookie set), or a two-factor challenge',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 400,
                description: 'The request does not come from a stateful https origin, so no cookie session can be opened (and no token was requested)',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Invalid credentials',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
            new OA\Response(
                response: 403,
                description: 'Account disabled, email address not confirmed, tenant approval pending or rejected, or invalid tenant access',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')
            ),
        ]
    )]
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'tenant_id' => ['nullable', 'uuid'],
            TokenMode::PARAMETER => ['sometimes', 'boolean'],
        ]);

        $tokenMode = $this->tokenModes->resolve($request);

        if (($blocked = $this->responder->cannotOpenAccess($request, $tokenMode)) !== null) {
            return $blocked;
        }

        /** @var User|null $user */
        $user = User::with('tenants')->where('email', $validated['email'])->first();

        // An unknown email still pays for one hash check, so the response time does not tell which emails exist.
        $passwordMatches = Hash::check($validated['password'], $user?->password ?? self::timingDummyHash());

        if ($user === null || ! $passwordMatches) {
            event(new Failed('sanctum', $user, ['email' => $validated['email']]));

            if ($user !== null) {
                $this->lockout->recordFailure($user);
            }

            return $this->problemDetailsResponse(
                title: 'Invalid Credentials',
                detail: 'The provided email or password does not match our records.',
                status: Response::HTTP_UNAUTHORIZED
            );
        }

        // Only someone who knows the password learns that the account is locked: the answer to a wrong password stays the same.
        if ($this->lockout->isLocked($user)) {
            return $this->problemDetailsResponse(
                title: __('enterprise_auth.errors.account_locked_title'),
                detail: __('enterprise_auth.errors.account_locked_detail'),
                status: Response::HTTP_LOCKED,
                type: AuthProblem::AccountLocked->value,
            );
        }

        $this->lockout->clear($user);

        if (! $user->is_active) {
            return $this->problemDetailsResponse(
                title: 'Account Disabled',
                detail: 'Your user account has been disabled. Please contact your organization administrator.',
                status: Response::HTTP_FORBIDDEN
            );
        }

        if ($user->email_verified_at === null) {
            return $this->problemDetailsResponse(
                title: __('registration.errors.email_not_verified_title'),
                detail: __('registration.errors.email_not_verified_detail'),
                status: Response::HTTP_FORBIDDEN,
                type: AuthProblem::EmailNotVerified->value,
            );
        }

        $tenants = $user->tenants;

        // Every membership was suspended by the identity provider (SCIM deactivation): the account has nowhere to sign in.
        if ($tenants->isEmpty() && $user->hasSuspendedTenantMembership()) {
            return $this->problemDetailsResponse(
                title: 'Account Disabled',
                detail: 'Your user account has been disabled. Please contact your organization administrator.',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $accessRequest = $tenants->isEmpty() ? $user->tenantAccessRequest : null;

        if ($accessRequest?->isPending()) {
            return $this->problemDetailsResponse(
                title: __('registration.errors.approval_pending_title'),
                detail: __('registration.errors.approval_pending_detail'),
                status: Response::HTTP_FORBIDDEN,
                type: AuthProblem::TenantApprovalPending->value,
            );
        }

        if ($accessRequest?->isRejected()) {
            return $this->problemDetailsResponse(
                title: __('registration.errors.access_rejected_title'),
                detail: __('registration.errors.access_rejected_detail'),
                status: Response::HTTP_FORBIDDEN,
                type: AuthProblem::TenantAccessRejected->value,
            );
        }

        $activeTenant = null;

        if (! empty($validated['tenant_id'])) {
            $activeTenant = $tenants->firstWhere('id', $validated['tenant_id']);
            if ($activeTenant === null) {
                return $this->problemDetailsResponse(
                    title: 'Invalid Tenant Access',
                    detail: 'You are not a member of the requested tenant.',
                    status: Response::HTTP_FORBIDDEN
                );
            }
        } else {
            $activeTenant = $tenants->first();
        }

        if ($activeTenant !== null && ! $user->is_platform_admin && $this->policies->forTenant($activeTenant)->ssoOnly) {
            $this->audit->record(RegistrationAudit::PASSWORD_LOGIN_DENIED_SSO_ONLY, (string) $user->id, [], (string) $activeTenant->id);

            return $this->problemDetailsResponse(
                title: __('enterprise_auth.errors.sso_required_title'),
                detail: __('enterprise_auth.errors.sso_required_detail'),
                status: Response::HTTP_FORBIDDEN,
                type: AuthProblem::SsoRequired->value,
            );
        }

        if ($this->passwordPolicy->passwordIsExpired($user)) {
            $this->audit->record(RegistrationAudit::PASSWORD_EXPIRED_LOGIN_DENIED, (string) $user->id, [], $activeTenant?->id);

            return $this->problemDetailsResponse(
                title: __('enterprise_auth.errors.password_expired_title'),
                detail: __('enterprise_auth.errors.password_expired_detail'),
                status: Response::HTTP_FORBIDDEN,
                type: AuthProblem::PasswordExpired->value,
            );
        }

        // With two factors the password alone gives no access token: a short-lived challenge is exchanged for one.
        if ($this->twoFactor->isEnabled($user)) {
            return $this->successResponse(
                data: [
                    'two_factor_required' => true,
                    'challenge_token' => $this->twoFactor->createChallenge($user, $activeTenant?->id, LoginResponder::METHOD_PASSWORD, $tokenMode),
                    'expires_in' => $this->twoFactor->challengeTtlMinutes() * 60,
                ],
                message: __('two_factor.messages.challenge_required')
            );
        }

        return $this->authenticatedResponse($request, $user, $activeTenant, $tenants, LoginResponder::METHOD_PASSWORD, $tokenMode);
    }

    /**
     * Exchange the challenge of a login with two factors for a Sanctum Bearer token.
     */
    #[OA\Post(
        path: '/api/v1/auth/two-factor-challenge',
        operationId: 'twoFactorChallenge',
        description: 'Second step of a login with two-factor authentication: the challenge_token returned by the login is exchanged for the access (the cookie session, or a Bearer token when token_mode was asked at login and is enabled) with a code of the authenticator app or a recovery code. A challenge expires after a few minutes and after repeated wrong codes; the user then logs in again.',
        summary: 'Complete a login with two factors',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['challenge_token'],
                properties: [
                    new OA\Property(property: 'challenge_token', type: 'string', example: 'wq3...64 chars'),
                    new OA\Property(property: 'code', description: 'Six-digit code of the authenticator app. Required without recovery_code.', type: 'string', example: '123456', nullable: true),
                    new OA\Property(property: 'recovery_code', description: 'Single-use recovery code. Required without code.', type: 'string', example: 'abcde-fghjk', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Authenticated successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Challenge expired or unknown, or wrong code', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Account disabled or no longer a member of the tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Too many attempts', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function twoFactorChallenge(TwoFactorChallengeRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $token = (string) $validated['challenge_token'];
        $challenge = $this->twoFactor->findChallenge($token);

        /** @var User|null $user */
        $user = $challenge === null ? null : User::with('tenants')->find($challenge['user_id']);

        if ($challenge === null || $user === null) {
            return $this->problemDetailsResponse(
                title: __('two_factor.errors.challenge_invalid_title'),
                detail: __('two_factor.errors.challenge_invalid_detail'),
                status: Response::HTTP_UNAUTHORIZED,
                type: AuthProblem::TwoFactorChallengeInvalid->value,
            );
        }

        // Nothing is spent when the request cannot open the access the challenge was issued for (no cookie session).
        if (($blocked = $this->responder->cannotOpenAccess($request, (bool) ($challenge['token_mode'] ?? false))) !== null) {
            return $blocked;
        }

        $method = $this->twoFactor->verifyCode($user, $validated['code'] ?? null, $validated['recovery_code'] ?? null);

        if ($method === null) {
            $this->twoFactor->recordFailedAttempt($token);
            $this->audit->record(RegistrationAudit::TWO_FACTOR_CHALLENGE_FAILED, (string) $user->id);
            event(new Failed('sanctum', $user, ['two_factor' => true]));

            return $this->problemDetailsResponse(
                title: __('two_factor.errors.code_invalid_title'),
                detail: __('two_factor.errors.code_invalid_detail'),
                status: Response::HTTP_UNAUTHORIZED,
                type: AuthProblem::TwoFactorCodeInvalid->value,
            );
        }

        $this->twoFactor->forgetChallenge($token);

        // The access is opened in the mode chosen at login: the challenge carries it, the client cannot switch here.
        $challengeTokenMode = (bool) ($challenge['token_mode'] ?? false);

        // The account may have changed in the minutes between the password and the code.
        if (! $user->is_active || $user->email_verified_at === null) {
            return $this->problemDetailsResponse(
                title: 'Account Disabled',
                detail: 'Your user account has been disabled. Please contact your organization administrator.',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $tenants = $user->tenants;
        $activeTenant = $challenge['tenant_id'] === null ? $tenants->first() : $tenants->firstWhere('id', $challenge['tenant_id']);

        if ($challenge['tenant_id'] !== null && $activeTenant === null) {
            return $this->problemDetailsResponse(
                title: 'Invalid Tenant Access',
                detail: 'You are not a member of the requested tenant.',
                status: Response::HTTP_FORBIDDEN
            );
        }

        if ($method === TwoFactorAuthentication::METHOD_RECOVERY_CODE) {
            $this->audit->record(RegistrationAudit::TWO_FACTOR_RECOVERY_CODE_USED, (string) $user->id, [
                'remaining_recovery_codes' => $this->twoFactor->remainingRecoveryCodes($user),
            ]);
        }

        return $this->authenticatedResponse($request, $user, $activeTenant, $tenants, (string) ($challenge['auth_method'] ?? LoginResponder::METHOD_PASSWORD), $challengeTokenMode);
    }

    /**
     * Opens the session and issues the access token of a user who passed every login check.
     *
     * @param  Collection<int, Tenant>  $tenants
     */
    private function authenticatedResponse(Request $request, User $user, ?Tenant $activeTenant, Collection $tenants, string $authMethod = LoginResponder::METHOD_PASSWORD, bool $tokenMode = false): JsonResponse
    {
        return $this->responder->respond($request, $user, $activeTenant, $tenants, $authMethod, $tokenMode);
    }

    /**
     * Return profile of the authenticated user.
     */
    #[OA\Get(
        path: '/api/v1/auth/profile',
        operationId: 'getProfile',
        description: 'Return profile of the authenticated user.',
        summary: 'Get user profile',
        security: [['cookieAuth' => []], ['bearerAuth' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Profile retrieved successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
        ]
    )]
    public function profile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->load('tenants');

        $activeTenant = $this->tenantContext->getTenant();

        return $this->successResponse(
            data: [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'locale' => $user->locale,
                'is_active' => $user->is_active,
                'active_tenant' => $activeTenant ? [
                    'id' => $activeTenant->id,
                    'name' => $activeTenant->name,
                    'country' => $activeTenant->country,
                    'role' => $user->getRoleInTenant($activeTenant->id),
                ] : null,
                'tenants' => $user->tenants->map(fn (Tenant $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'role' => $t->pivot?->role,
                ]),
            ],
            message: 'Profile retrieved successfully'
        );
    }

    /**
     * Switch active tenant context for the user session.
     */
    #[OA\Post(
        path: '/api/v1/auth/switch-tenant',
        operationId: 'switchTenant',
        description: 'Switch active tenant context for the user session.',
        summary: 'Switch tenant context',
        security: [['cookieAuth' => []], ['bearerAuth' => []]],
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', nullable: true, example: '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Active organization switched successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
            new OA\Response(
                response: 403,
                description: 'Forbidden - not a member of the requested tenant',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')
            ),
        ]
    )]
    public function switchTenant(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (empty($validated['tenant_id'])) {
            $this->tenantContext->setTenant(null);

            return $this->successResponse(
                data: [
                    'tenant_id' => null,
                    'name' => null,
                    'country' => null,
                    'role' => null,
                ],
                message: 'Active organization cleared successfully'
            );
        }

        if (! $user->belongsToTenant($validated['tenant_id'])) {
            return $this->problemDetailsResponse(
                title: 'Forbidden Tenant',
                detail: 'You do not belong to the selected tenant.',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $tenant = Tenant::findOrFail($validated['tenant_id']);
        $this->tenantContext->setTenant($tenant);

        return $this->successResponse(
            data: [
                'tenant_id' => $tenant->id,
                'name' => $tenant->name,
                'country' => $tenant->country,
                'role' => $user->getRoleInTenant($tenant->id),
            ],
            message: 'Active organization switched successfully'
        );
    }

    /**
     * Revoke current access token.
     */
    #[OA\Post(
        path: '/api/v1/auth/logout',
        operationId: 'logout',
        description: 'Cookie session: the session is invalidated (cookie and server-side state). Token mode: the current Bearer token is revoked. The local session always ends first. When the access was opened through the single sign-on of the tenant and the identity provider supports a logout (OIDC end_session_endpoint, SAML single logout service), `data.idp_logout_url` is the URL the SPA must send the browser to so the identity provider ends its session too (OIDC RP-initiated logout with id_token_hint and the https post_logout_redirect_uri; SAML LogoutRequest); otherwise it is null.',
        summary: 'User logout',
        security: [['cookieAuth' => []], ['bearerAuth' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successfully logged out',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
        ]
    )]
    public function logout(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        $token = $user?->currentAccessToken();

        // How this access was opened, read before it is ended: an SSO access also ends at the identity provider.
        $ssoTenantId = $this->ssoTenantOf($request, $token);

        // The local session always ends first, whatever happens at the identity provider.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($user) {
            event(new Logout('sanctum', $user));
        }

        $idpLogoutUrl = $user !== null && $ssoTenantId !== null ? $this->ssoLogout->urlFor($user, $ssoTenantId) : null;

        return $this->successResponse(
            data: ['idp_logout_url' => $idpLogoutUrl],
            message: 'Successfully logged out'
        );
    }

    /**
     * The tenant whose single sign-on opened the access of the request, or null for a password access.
     */
    private function ssoTenantOf(Request $request, mixed $token): ?string
    {
        if ($token instanceof PersonalAccessToken && $token->exists) {
            return $token->getAttribute('auth_method') === LoginResponder::METHOD_SSO ? ($token->getAttribute('auth_tenant_id') ?: null) : null;
        }

        if (! $request->hasSession() || $request->session()->get(EnforceSessionPolicy::SESSION_METHOD) !== LoginResponder::METHOD_SSO) {
            return null;
        }

        $tenantId = $request->session()->get(EnforceSessionPolicy::SESSION_TENANT);

        return is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
    }

    /**
     * Refresh bearer token.
     */
    #[OA\Post(
        path: '/api/v1/auth/refresh',
        operationId: 'refreshToken',
        description: 'Cookie session: the session id is regenerated and the body carries no token. Token mode (only when enabled on the server): the Bearer token is rotated and the new one returned.',
        summary: 'Refresh access token',
        security: [['cookieAuth' => []], ['bearerAuth' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Token refreshed successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
        ]
    )]
    public function refresh(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        // A cookie session is renewed in place: a new session id, and still no token in the body.
        if (! $token instanceof PersonalAccessToken) {
            if ($request->hasSession()) {
                $request->session()->regenerate();
            }

            return $this->successResponse(
                data: ['auth_mode' => 'cookie'],
                message: 'Session refreshed successfully'
            );
        }

        // A token is rotated: the old one stops working as soon as the new one is issued.
        $token->delete();
        [$newToken, $expiresAt] = $this->responder->issueToken($request, $user, (string) ($token->getAttribute('auth_method') ?: LoginResponder::METHOD_PASSWORD), $token->getAttribute('auth_tenant_id') ?: null);

        return $this->successResponse(
            data: [
                'auth_mode' => 'token',
                'token' => $newToken,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt,
            ],
            message: 'Token refreshed successfully'
        );
    }
}
