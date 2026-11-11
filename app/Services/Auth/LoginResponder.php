<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\Auth\AuthProblem;
use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Security\ConcurrentSessionLimit;
use App\Services\Auth\Security\TenantSecurityPolicies;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens the access of a user who passed every login check, by password or by single sign-on. The access is the Sanctum
 * cookie session (HttpOnly, Secure, CSRF-protected): the session id is regenerated at login (no fixation) and the body
 * never carries a token. A Bearer token is issued only in the explicit token mode (`auth.token_mode.enabled` on and
 * `token_mode: true` asked by the client). The way the access was opened (and, for SSO, the tenant whose identity provider
 * opened it) is recorded on the token or in the browser session, where EnforceSessionPolicy reads it. The concurrent
 * session limit of the tenant is applied here, so the new access always fits.
 *
 * A single sign-on that finishes on a request without a session (the SAML assertion posted by the identity provider) is
 * handed over to the SPA with a single-use code instead (SsoSignInCodes).
 */
final class LoginResponder
{
    use ApiResponse;

    public const METHOD_PASSWORD = 'password';

    public const METHOD_SSO = 'sso';

    public function __construct(
        private readonly TwoFactorAuthentication $twoFactor,
        private readonly TenantSecurityPolicies $policies,
        private readonly ConcurrentSessionLimit $sessionLimit,
        private readonly TokenMode $tokenModes,
        private readonly SsoSignInCodes $signInCodes,
    ) {}

    /**
     * The problem answered to a login that can neither open a cookie session (the request does not come from a stateful
     * first-party origin) nor, with the token mode off, issue a token. Null when the access can be opened.
     */
    public function cannotOpenAccess(Request $request, bool $tokenMode): ?JsonResponse
    {
        if ($tokenMode || $request->hasSession()) {
            return null;
        }

        return $this->problemDetailsResponse(
            title: __('enterprise_auth.errors.cookie_session_required_title'),
            detail: __('enterprise_auth.errors.cookie_session_required_detail'),
            status: Response::HTTP_BAD_REQUEST,
            type: AuthProblem::CookieSessionRequired->value,
        );
    }

    /**
     * The answer to a user who signed in through the single sign-on of a tenant: the access, or, when the user has two
     * factors, a challenge that keeps the SSO method and the tenant. Without a session (and without the token mode) the
     * sign-in is handed over with a single-use code.
     */
    public function respondToSso(Request $request, User $user, Tenant $tenant, bool $tokenMode = false): Response
    {
        $tenants = $user->tenants()->get();

        if (! $tokenMode && ! $request->hasSession()) {
            return $this->handOver($request, $user, $tenant);
        }

        if ($this->twoFactor->isEnabled($user)) {
            return $this->successResponse(
                data: [
                    'two_factor_required' => true,
                    'challenge_token' => $this->twoFactor->createChallenge($user, (string) $tenant->id, self::METHOD_SSO, $tokenMode),
                    'expires_in' => $this->twoFactor->challengeTtlMinutes() * 60,
                ],
                message: __('two_factor.messages.challenge_required')
            );
        }

        return $this->respond($request, $user, $tenants->firstWhere('id', $tenant->id), $tenants, self::METHOD_SSO, $tokenMode);
    }

    /**
     * The sign-in code of a single sign-on that finished without a session: JSON for a client that asked for JSON, a 303
     * redirect to the SPA (the code in the URL fragment) for the browser navigation of the identity provider.
     */
    private function handOver(Request $request, User $user, Tenant $tenant): Response
    {
        $code = $this->signInCodes->issue($user, $tenant);

        if ($request->expectsJson() || $request->isJson()) {
            return $this->successResponse(
                data: ['sign_in_code' => $code, 'expires_in' => $this->signInCodes->ttlSeconds()],
                message: __('enterprise_auth.messages.sign_in_code_issued')
            );
        }

        $target = rtrim((string) config('app.frontend_url'), '/').'/'.ltrim((string) config('auth.sso_exchange.frontend_path'), '/');

        return redirect()->away($target.'#code='.$code, Response::HTTP_SEE_OTHER);
    }

    /**
     * @param  Collection<int, Tenant>  $tenants
     */
    public function respond(Request $request, User $user, ?Tenant $activeTenant, Collection $tenants, string $authMethod = self::METHOD_PASSWORD, bool $tokenMode = false): JsonResponse
    {
        if ($tokenMode && ! $this->tokenModes->enabled()) {
            return $this->problemDetailsResponse(
                title: __('enterprise_auth.errors.token_mode_disabled_title'),
                detail: __('enterprise_auth.errors.token_mode_disabled'),
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (($blocked = $this->cannotOpenAccess($request, $tokenMode)) !== null) {
            return $blocked;
        }

        $token = null;
        $expiresAt = null;
        $tokenId = null;
        $boundTenantId = $authMethod === self::METHOD_SSO ? $activeTenant?->id : null;

        if (! $tokenMode) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
            $request->session()->put(EnforceSessionPolicy::SESSION_METHOD, $authMethod);
            $request->session()->put(EnforceSessionPolicy::SESSION_TENANT, $boundTenantId);
            $request->session()->put(EnforceSessionPolicy::SESSION_ACTIVITY, now()->getTimestamp());
        } else {
            [$token, $expiresAt, $tokenId] = $this->issueToken($request, $user, $authMethod, $boundTenantId);
        }

        $this->sessionLimit->enforce($user, $activeTenant, $tokenId, $tokenMode ? null : $request->session()->getId());

        event(new Login('sanctum', $user, false));

        $role = $this->roleIn($activeTenant);

        $credential = $tokenMode
            ? ['token' => $token, 'token_type' => 'Bearer', 'expires_at' => $expiresAt]
            : [];

        return $this->successResponse(
            data: [
                'auth_mode' => $tokenMode ? 'token' : 'cookie',
                ...$credential,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'locale' => $user->locale,
                    'two_factor_enabled' => $this->twoFactor->isEnabled($user),
                ],
                'two_factor_setup_required' => ! $this->twoFactor->isEnabled($user) && $this->policies->requiresTwoFactor($activeTenant, $role),
                'active_tenant' => $activeTenant ? [
                    'id' => $activeTenant->id,
                    'name' => $activeTenant->name,
                    'legal_name' => $activeTenant->legal_name,
                    'country' => $activeTenant->country,
                    'default_currency' => $activeTenant->default_currency,
                    'role' => $role,
                ] : null,
                'available_tenants' => $tenants->map(fn (Tenant $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'country' => $t->country,
                    'role' => $this->roleIn($t),
                ]),
            ],
            message: 'Authenticated successfully'
        );
    }

    /**
     * The role of the user in a tenant loaded through the membership relation.
     */
    private function roleIn(?Tenant $tenant): ?string
    {
        $pivot = $tenant?->getRelations()['pivot'] ?? null;

        return $pivot?->role;
    }

    /**
     * @return array{0: string, 1: string|null, 2: string}
     */
    public function issueToken(Request $request, User $user, string $authMethod, ?string $boundTenantId): array
    {
        if (! $this->tokenModes->enabled()) {
            throw new LogicException('A bearer token is issued only while auth.token_mode.enabled is on.');
        }

        $minutes = config('sanctum.expiration');
        $expiresAt = $minutes === null ? null : now()->addMinutes((int) $minutes);
        $client = mb_substr(trim((string) $request->userAgent()), 0, 120);
        $name = $client === '' ? 'api-token' : $client;

        $newToken = $user->createToken($name, ['*'], $expiresAt);

        /** @var PersonalAccessToken $record */
        $record = $newToken->accessToken;
        $record->forceFill([
            'auth_method' => $authMethod,
            'auth_tenant_id' => $boundTenantId,
            'last_activity_at' => now(),
        ])->save();

        return [$newToken->plainTextToken, $expiresAt?->toIso8601String(), (string) $record->getKey()];
    }
}
