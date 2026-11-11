<?php

declare(strict_types=1);

namespace App\Services\Auth\Saml;

use App\Models\SamlLoginState;
use App\Models\SamlProvider;
use App\Models\SsoIdentity;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\Sso\SsoCompletion;
use App\Services\Auth\Sso\SsoConnection;
use App\Services\Auth\Sso\SsoException;
use App\Services\Auth\Sso\SsoLogin;
use App\Services\Auth\UserSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\LogoutRequest;
use OneLogin\Saml2\Settings;
use Throwable;

/**
 * SAML 2.0 web browser SSO, SP-initiated, for the tenants that configured a SAML provider. SAML 2.0 specifications:
 * Core and Profiles (OASIS, 2005); the XML signature, audience, time and SubjectConfirmation checks are done by
 * onelogin/php-saml in strict mode.
 *
 * What this class adds on top of the library:
 * - the login is SP-initiated only: it starts with a stored state (RelayState hash and AuthnRequest id); a response
 *   without a known RelayState, an IdP-initiated one, is refused, and the response must answer that very request
 *   (InResponseTo);
 * - the state belongs to a tenant and is consumed atomically; the tenant of the URL must be the tenant of the state;
 * - an assertion is accepted once: its id is remembered until it expires (replay);
 * - the account rules (allowed domains, linking, JIT, roles) are those of SsoLogin, shared with OpenID Connect.
 *
 * Email: SAML has no `email_verified` claim. The address is trusted because it comes in an assertion signed by the
 * identity provider of the tenant and must be in the allowed domains of that tenant; it is read from the configured
 * attribute, or a well-known one, or the NameID when that is an email address.
 *
 * Single logout: a signed LogoutRequest of the identity provider ends the accesses the user opened through this tenant.
 */
final class SamlLogin
{
    private const EMAIL_ATTRIBUTES = [
        'email',
        'mail',
        'emailAddress',
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
        'urn:oid:0.9.2342.19200300.100.1.3',
    ];

    private const NAME_ATTRIBUTES = [
        'name',
        'displayName',
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
        'urn:oid:2.16.840.1.113730.3.1.241',
    ];

    public function __construct(
        private readonly SamlSettings $settings,
        private readonly SsoLogin $login,
        private readonly RegistrationAudit $audit,
        private readonly UserSessions $sessions,
    ) {}

    /**
     * The service provider metadata of the tenant, to give to its identity provider.
     *
     * @throws SsoException when the tenant has no SAML provider
     */
    public function metadata(string $tenantId): string
    {
        $provider = $this->provider($tenantId);

        try {
            $settings = new Settings($this->settings->build($provider), true);
            $xml = $settings->getSPMetadata();

            if ($settings->validateMetadata($xml) !== []) {
                throw new SsoException('metadata_invalid');
            }

            return $xml;
        } catch (SsoException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new SsoException('metadata_invalid');
        }
    }

    /**
     * Starts a login: stores the state and returns the URL of the identity provider (HTTP-Redirect binding).
     *
     * @throws SsoException when the tenant has no ready SAML provider
     */
    public function start(string $tenantId, ?string $bindingHash = null): string
    {
        $provider = $this->provider($tenantId);

        if (! $provider->isReady()) {
            throw (new SsoException('provider_unavailable'))->forTenant($tenantId);
        }

        try {
            $auth = new Auth($this->settings->build($provider));
            $relayState = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $url = (string) $auth->login($relayState, [], false, false, true);
            $requestId = (string) $auth->getLastRequestID();
        } catch (Throwable $exception) {
            throw (new SsoException('request_failed', $exception->getMessage()))->forTenant($tenantId);
        }

        $state = new SamlLoginState([
            'saml_provider_id' => $provider->id,
            'relay_state_hash' => hash('sha256', $relayState),
            'request_id' => $requestId,
            'browser_binding_hash' => $bindingHash,
            'expires_at' => now()->addMinutes((int) config('sso.state_ttl_minutes')),
        ]);
        $state->forceFill(['tenant_id' => $tenantId])->save();

        $this->audit->record(RegistrationAudit::SSO_LOGIN_STARTED, null, ['provider' => $provider->id, 'protocol' => SsoConnection::SAML], $tenantId);

        return $url;
    }

    /**
     * Completes a login with the SAMLResponse posted to the assertion consumer service of the tenant.
     *
     * @throws SsoException
     */
    public function complete(string $tenantId, string $samlResponse, string $relayState, Request $request): SsoCompletion
    {
        try {
            $state = $this->consume($tenantId, $relayState);

            try {
                $provider = $this->provider($tenantId);

                if (! $provider->isReady()) {
                    throw new SsoException('provider_unavailable');
                }

                [$nameId, $attributes, $sessionIndex] = $this->validatedAssertion($provider, $samlResponse, $state->request_id, $request);
                $claims = $this->claims($provider, $nameId, $attributes);
                [$user, $tenant] = $this->login->signInConnection(SsoConnection::fromSaml($provider), $nameId, $claims, ['session_index' => $sessionIndex]);

                return new SsoCompletion($user, $tenant, $state->browser_binding_hash);
            } catch (SsoException $exception) {
                throw $exception->forTenant($tenantId);
            }
        } catch (SsoException $exception) {
            throw $this->login->failed($exception, $tenantId, SsoConnection::SAML);
        }
    }

    /**
     * Single logout: a LogoutRequest (or the response to one) of the identity provider, HTTP-Redirect binding. A request
     * must be signed; it ends the accesses of the user opened through this tenant, and the URL of the LogoutResponse
     * to send the browser to is returned. A LogoutResponse needs no answer (null).
     *
     * @throws SsoException
     */
    public function logout(string $tenantId, Request $request): ?string
    {
        $provider = $this->provider($tenantId);
        $isRequest = $request->has('SAMLRequest');

        if (! $isRequest && ! $request->has('SAMLResponse')) {
            throw new SsoException('logout_message_missing');
        }

        if ($isRequest && (! $request->has('Signature') || ! $request->has('SigAlg'))) {
            throw new SsoException('logout_unsigned');
        }

        $nameId = null;

        try {
            $auth = new Auth($this->settings->build($provider));

            if ($isRequest) {
                $logoutRequest = new LogoutRequest($auth->getSettings(), (string) $request->input('SAMLRequest'));
                $nameId = LogoutRequest::getNameId($logoutRequest->getXML());
            }

            $url = $this->inRequestScope($request, [], $request->query->all(), fn () => $auth->processSLO(true, null, false, null, true));
        } catch (Throwable $exception) {
            throw (new SsoException('logout_invalid', $exception->getMessage()))->forTenant($tenantId);
        }

        if ($auth->getErrors() !== []) {
            throw (new SsoException('logout_invalid', (string) $auth->getLastErrorReason()))->forTenant($tenantId);
        }

        if ($nameId !== null) {
            $this->endAccesses($provider, (string) $nameId);
        }

        return $url === '' ? null : $url;
    }

    /**
     * @return array{0: string, 1: array<string, list<string>>, 2: string|null} the NameID, the attributes and the session index of a valid assertion
     *
     * @throws SsoException
     */
    private function validatedAssertion(SamlProvider $provider, string $samlResponse, string $requestId, Request $request): array
    {
        try {
            $auth = new Auth($this->settings->build($provider));
            $this->inRequestScope($request, ['SAMLResponse' => $samlResponse], [], fn () => $auth->processResponse($requestId));
        } catch (Throwable $exception) {
            throw new SsoException('assertion_invalid', $exception->getMessage());
        }

        if ($auth->getErrors() !== [] || ! $auth->isAuthenticated()) {
            throw new SsoException('assertion_invalid', implode(',', $auth->getErrors()).': '.(string) $auth->getLastErrorReason());
        }

        $assertionId = (string) $auth->getLastAssertionId();
        $notOnOrAfter = (int) $auth->getLastAssertionNotOnOrAfter();
        $nameId = (string) $auth->getNameId();

        if ($assertionId === '' || $nameId === '' || strlen($nameId) > 255) {
            throw new SsoException('assertion_incomplete');
        }

        $remaining = max(60, $notOnOrAfter - time() + 600);

        if (! Cache::add('saml-assertion:'.hash('sha256', $provider->id.'|'.$assertionId), 1, $remaining)) {
            throw new SsoException('assertion_replayed');
        }

        /** @var array<string, list<string>> $attributes */
        $attributes = $auth->getAttributes();

        $sessionIndex = $auth->getSessionIndex();

        return [$nameId, $attributes, is_string($sessionIndex) && $sessionIndex !== '' ? $sessionIndex : null];
    }

    /**
     * @param  array<string, list<string>>  $attributes
     * @return array<string, mixed>
     */
    private function claims(SamlProvider $provider, string $nameId, array $attributes): array
    {
        $email = $this->firstValue($attributes, array_filter([$provider->email_attribute, ...self::EMAIL_ATTRIBUTES]));

        if ($email === null && filter_var($nameId, FILTER_VALIDATE_EMAIL) !== false) {
            $email = $nameId;
        }

        return array_merge($attributes, [
            'email' => $email,
            // The assertion is signed by the identity provider of the tenant and the domain is checked by SsoLogin.
            'email_verified' => true,
            'name' => $this->firstValue($attributes, array_filter([$provider->name_attribute, ...self::NAME_ATTRIBUTES])),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $names
     */
    private function firstValue(array $attributes, array $names): ?string
    {
        foreach ($names as $name) {
            $values = $attributes[$name] ?? null;
            $value = is_array($values) ? ($values[0] ?? null) : null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Spends the state of the RelayState, once, and only for the tenant it was started for.
     *
     * @throws SsoException
     */
    private function consume(string $tenantId, string $relayState): SamlLoginState
    {
        /** @var SamlLoginState|null $state */
        $state = SamlLoginState::withoutGlobalScopes()->where('relay_state_hash', hash('sha256', $relayState))->first();

        if ($state === null) {
            throw (new SsoException('state_unknown'))->forTenant($tenantId);
        }

        if ((string) $state->tenant_id !== $tenantId) {
            throw (new SsoException('state_tenant_mismatch'))->forTenant($tenantId);
        }

        $updated = SamlLoginState::withoutGlobalScopes()->whereKey($state->id)->whereNull('consumed_at')->where('expires_at', '>', now())->update(['consumed_at' => now()]);

        if ($updated !== 1) {
            throw (new SsoException($state->consumed_at !== null ? 'state_replayed' : 'state_expired'))->forTenant($tenantId);
        }

        return $state;
    }

    private function endAccesses(SamlProvider $provider, string $nameId): void
    {
        $identity = SsoIdentity::withoutGlobalScopes()->where('saml_provider_id', $provider->id)->where('subject', $nameId)->first();
        $user = $identity === null ? null : User::query()->find($identity->user_id);

        if ($user === null) {
            return;
        }

        $this->sessions->revokeTenantBound($user, (string) $provider->tenant_id);
        $this->audit->record(RegistrationAudit::SSO_LOGOUT, (string) $user->id, ['provider' => $provider->id, 'protocol' => SsoConnection::SAML], (string) $provider->tenant_id);
    }

    /**
     * @throws SsoException
     */
    private function provider(string $tenantId): SamlProvider
    {
        /** @var SamlProvider|null $provider */
        $provider = SamlProvider::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();

        return $provider ?? throw (new SsoException('provider_unavailable'))->forTenant($tenantId);
    }

    /**
     * onelogin/php-saml reads the message and the URL of the request from the PHP superglobals. They are set from the
     * Laravel request for the duration of the call and restored afterwards.
     *
     * @template TReturn
     *
     * @param  array<string, mixed>  $post
     * @param  array<string, mixed>  $get
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function inRequestScope(Request $request, array $post, array $get, callable $callback): mixed
    {
        $saved = [$_SERVER, $_POST, $_GET];

        $_POST = $post;
        $_GET = $get;
        $_SERVER['HTTP_HOST'] = $request->getHttpHost();
        $_SERVER['HTTPS'] = $request->isSecure() ? 'on' : 'off';
        $_SERVER['SERVER_PORT'] = (string) $request->getPort();
        $_SERVER['REQUEST_URI'] = Str::before($request->getRequestUri(), '?').($request->getQueryString() === null ? '' : '?'.$request->getQueryString());
        $_SERVER['QUERY_STRING'] = (string) $request->getQueryString();

        try {
            return $callback();
        } finally {
            [$_SERVER, $_POST, $_GET] = $saved;
        }
    }
}
