<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Auth\AuthProblem;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\Security\TenantSecurityPolicies;
use App\Services\Auth\UserSessions;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces on every authenticated request the session rules of the active tenant:
 *
 * - SSO-only: an access opened with a password is refused (platform administrators excepted, for support);
 * - an access opened through the single sign-on of a tenant works only in that tenant (the identity provider of one
 *   company must not open the other companies of a user who belongs to several);
 * - idle timeout: an access unused for longer than the limit is revoked.
 *
 * The way an access was opened and its last activity are recorded on the Bearer token, or in the browser session.
 * API keys are not users and are not affected.
 */
final class EnforceSessionPolicy
{
    use ApiResponse;

    public const SESSION_METHOD = 'security.auth_method';

    public const SESSION_TENANT = 'security.auth_tenant_id';

    public const SESSION_ACTIVITY = 'security.last_activity_at';

    /**
     * Seconds between two writes of the last activity of a Bearer token.
     */
    private const ACTIVITY_WRITE_INTERVAL = 60;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantSecurityPolicies $policies,
        private readonly RegistrationAudit $audit,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = $this->tenantContext->getTenant();

        if (! $user instanceof User || $tenant === null || is_array($request->attributes->get('api_key_scopes'))) {
            return $next($request);
        }

        // Only a stored token row carries session data: a cookie session, a test double or a transient token has none.
        $token = UserSessions::currentTokenId($user) === null ? null : $user->currentAccessToken();
        $policy = $this->policies->forTenant($tenant);
        $method = $token !== null ? (string) $token->getAttribute('auth_method') : $this->sessionValue($request, self::SESSION_METHOD, 'password');
        $boundTenant = $token !== null ? $token->getAttribute('auth_tenant_id') : $this->sessionValue($request, self::SESSION_TENANT, null);

        if ($method === 'sso' && $boundTenant !== null && $boundTenant !== (string) $tenant->id) {
            return $this->problemDetailsResponse(
                title: __('enterprise_auth.errors.session_tenant_mismatch_title'),
                detail: __('enterprise_auth.errors.session_tenant_mismatch_detail'),
                status: Response::HTTP_FORBIDDEN,
                type: AuthProblem::SessionTenantMismatch->value,
            );
        }

        if ($policy->ssoOnly && $method !== 'sso' && ! $user->is_platform_admin) {
            return $this->problemDetailsResponse(
                title: __('enterprise_auth.errors.sso_required_title'),
                detail: __('enterprise_auth.errors.sso_required_detail'),
                status: Response::HTTP_FORBIDDEN,
                type: AuthProblem::SsoRequired->value,
            );
        }

        $last = $this->lastActivity($request, $token);

        if ($policy->sessionIdleTimeoutMinutes !== null && $last !== null && $last->copy()->addMinutes($policy->sessionIdleTimeoutMinutes)->isPast()) {
            $this->expire($request, $user, $token, (string) $tenant->id);

            return $this->problemDetailsResponse(
                title: __('enterprise_auth.errors.session_expired_title'),
                detail: __('enterprise_auth.errors.session_expired_detail'),
                status: Response::HTTP_UNAUTHORIZED,
                type: AuthProblem::SessionExpired->value,
            );
        }

        $this->touch($request, $token, $last);

        return $next($request);
    }

    private function lastActivity(Request $request, ?PersonalAccessToken $token): ?Carbon
    {
        if ($token !== null) {
            $at = $token->getAttribute('last_activity_at');

            return $at === null ? null : Carbon::parse($at);
        }

        $at = $this->sessionValue($request, self::SESSION_ACTIVITY, null);

        return $at === null ? null : Carbon::createFromTimestamp((int) $at);
    }

    /**
     * A token or session without a recorded activity (opened before the policy existed) starts counting now.
     */
    private function touch(Request $request, ?PersonalAccessToken $token, ?Carbon $last): void
    {
        if ($token !== null) {
            if ($last === null || $last->diffInSeconds(now(), true) >= self::ACTIVITY_WRITE_INTERVAL) {
                $token->forceFill(['last_activity_at' => now()])->saveQuietly();
            }

            return;
        }

        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_ACTIVITY, now()->getTimestamp());
        }
    }

    private function expire(Request $request, User $user, ?PersonalAccessToken $token, string $tenantId): void
    {
        $token?->delete();

        if ($token === null && $request->hasSession()) {
            $request->session()->invalidate();
        }

        $this->audit->record(RegistrationAudit::SESSION_IDLE_EXPIRED, (string) $user->id, [], $tenantId);
    }

    private function sessionValue(Request $request, string $key, ?string $default): ?string
    {
        if (! $request->hasSession()) {
            return $default;
        }

        $value = $request->session()->get($key, $default);

        return $value === null ? null : (string) $value;
    }
}
