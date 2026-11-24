<?php

declare(strict_types=1);

namespace App\Services\Auth\Sso;

use App\Models\SamlProvider;
use App\Models\SsoIdentity;
use App\Models\SsoProvider;
use App\Models\User;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\Saml\SamlSettings;
use Illuminate\Support\Str;
use OneLogin\Saml2\Auth;
use Throwable;

/**
 * Logout started by the service provider, for a user who signed in through the single sign-on of a tenant. It is only
 * the second half of the logout: the local session and tokens are ended first, by the application logout, whatever this
 * returns; this builds the URL the browser is sent to so the identity provider ends its own session too.
 *
 * - OpenID Connect RP-Initiated Logout 1.0: the `end_session_endpoint` of the provider with `id_token_hint` (the id_token
 *   of the last login, kept encrypted on the identity), `client_id`, a random `state` and, when the tenant configured it,
 *   the `post_logout_redirect_uri` (https only, checked when the settings are saved and again here);
 * - SAML 2.0 Single Logout, SP-initiated: a LogoutRequest (HTTP-Redirect binding, signed when the tenant has a service
 *   provider key) with the NameID and the SessionIndex of the last login, sent to the single logout service of the
 *   identity provider. The answer comes back to the SLO endpoint of the tenant.
 *
 * The hint is spent: the stored id_token or session index is cleared when the URL is built. Without an end session
 * endpoint or a single logout service there is nothing to start and the result is null.
 */
final class SsoLogout
{
    public function __construct(
        private readonly SsoUrlGuard $guard,
        private readonly SamlSettings $samlSettings,
        private readonly RegistrationAudit $audit,
    ) {}

    /**
     * The URL of the identity provider logout for the user, or null when there is none to start.
     */
    public function urlFor(User $user, string $tenantId): ?string
    {
        /** @var SsoIdentity|null $identity */
        $identity = SsoIdentity::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('user_id', $user->id)->latest('last_login_at')->first();

        if ($identity === null) {
            return null;
        }

        try {
            $url = $identity->saml_provider_id !== null ? $this->samlUrl($identity, $tenantId) : $this->oidcUrl($identity, $tenantId);
        } catch (Throwable) {
            $url = null;
        }

        $identity->forceFill(['last_id_token' => null, 'last_session_index' => null])->save();

        if ($url !== null) {
            $this->audit->record(RegistrationAudit::SSO_LOGOUT, (string) $user->id, ['protocol' => $identity->saml_provider_id !== null ? SsoConnection::SAML : SsoConnection::OIDC, 'initiated_by' => 'service_provider'], $tenantId);
        }

        return $url;
    }

    private function oidcUrl(SsoIdentity $identity, string $tenantId): ?string
    {
        /** @var SsoProvider|null $provider */
        $provider = SsoProvider::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($identity->sso_provider_id)->first();
        $endpoint = $provider?->end_session_endpoint;

        if ($provider === null || ! $provider->enabled || $endpoint === null || $endpoint === '') {
            return null;
        }

        $this->guard->assertSafe($endpoint, 'end_session_endpoint');

        $query = ['client_id' => $provider->client_id, 'state' => Str::random(32)];

        if ($identity->last_id_token !== null && $identity->last_id_token !== '') {
            $query['id_token_hint'] = $identity->last_id_token;
        }

        if ($provider->post_logout_redirect_uri !== null && $provider->post_logout_redirect_uri !== '') {
            $this->guard->assertHttps($provider->post_logout_redirect_uri, 'post_logout_redirect_uri');
            $query['post_logout_redirect_uri'] = $provider->post_logout_redirect_uri;
        }

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function samlUrl(SsoIdentity $identity, string $tenantId): ?string
    {
        /** @var SamlProvider|null $provider */
        $provider = SamlProvider::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($identity->saml_provider_id)->first();

        if ($provider === null || ! $provider->enabled || blank($provider->idp_slo_url)) {
            return null;
        }

        $auth = new Auth($this->samlSettings->build($provider));
        $url = $auth->logout(null, [], $identity->subject, $identity->last_session_index, true);

        return $url === '' ? null : $url;
    }
}
