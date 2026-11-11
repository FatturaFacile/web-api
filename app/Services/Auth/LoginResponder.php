<?php

declare(strict_types=1);

namespace App\Services\Auth;

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

/**
 * Opens the session and issues the access token of a user who passed every login check, by password or by single
 * sign-on. The way the access was opened (and, for SSO, the tenant whose identity provider opened it) is recorded on the
 * token or in the browser session, where EnforceSessionPolicy reads it. The concurrent session limit of the tenant is
 * applied here, so the new access always fits.
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
    ) {}

    /**
     * The answer to a user who signed in through the single sign-on of a tenant: the access, or, when the user has two
     * factors, a challenge that keeps the SSO method and the tenant.
     */
    public function respondToSso(Request $request, User $user, Tenant $tenant): JsonResponse
    {
        $tenants = $user->tenants()->get();

        if ($this->twoFactor->isEnabled($user)) {
            return $this->successResponse(
                data: [
                    'two_factor_required' => true,
                    'challenge_token' => $this->twoFactor->createChallenge($user, (string) $tenant->id, self::METHOD_SSO),
                    'expires_in' => $this->twoFactor->challengeTtlMinutes() * 60,
                ],
                message: __('two_factor.messages.challenge_required')
            );
        }

        return $this->respond($request, $user, $tenants->firstWhere('id', $tenant->id), $tenants, self::METHOD_SSO);
    }

    /**
     * @param  Collection<int, Tenant>  $tenants
     */
    public function respond(Request $request, User $user, ?Tenant $activeTenant, Collection $tenants, string $authMethod = self::METHOD_PASSWORD): JsonResponse
    {
        // Cookie mode: the request comes from a first-party SPA (Sanctum stateful domain), so it carries a session. The
        // session cookie is HttpOnly and CSRF-protected, and no token is put in the body, where a script could read it.
        // Token mode: every other client gets an expiring Bearer token.
        $cookieMode = $request->hasSession();
        $token = null;
        $expiresAt = null;
        $tokenId = null;
        $boundTenantId = $authMethod === self::METHOD_SSO ? $activeTenant?->id : null;

        if ($cookieMode) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
            $request->session()->put(EnforceSessionPolicy::SESSION_METHOD, $authMethod);
            $request->session()->put(EnforceSessionPolicy::SESSION_TENANT, $boundTenantId);
            $request->session()->put(EnforceSessionPolicy::SESSION_ACTIVITY, now()->getTimestamp());
        } else {
            [$token, $expiresAt, $tokenId] = $this->issueToken($request, $user, $authMethod, $boundTenantId);
        }

        $this->sessionLimit->enforce($user, $activeTenant, $tokenId, $cookieMode ? $request->session()->getId() : null);

        event(new Login('sanctum', $user, false));

        $role = $this->roleIn($activeTenant);

        return $this->successResponse(
            data: [
                'auth_mode' => $cookieMode ? 'cookie' : 'token',
                'token' => $token,
                'token_type' => $cookieMode ? null : 'Bearer',
                'expires_at' => $expiresAt,
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
