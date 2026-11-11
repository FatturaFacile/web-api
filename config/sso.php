<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Single sign-on (OpenID Connect) and SCIM provisioning
|--------------------------------------------------------------------------
|
| OpenID Connect Core 1.0 authorization code flow with PKCE (RFC 7636). SAML is not supported: it needs an XML
| signature library, and no Composer package may be added for it.
|
*/

return [
    // How long a started login (state and nonce) stays valid.
    'state_ttl_minutes' => (int) env('SSO_STATE_TTL_MINUTES', 10),

    // Timeout of every call to the identity provider (discovery, JWKS, token endpoint), in seconds.
    'http_timeout_seconds' => (int) env('SSO_HTTP_TIMEOUT_SECONDS', 10),

    // How long the keys of a provider are cached; an unknown `kid` forces one refresh.
    'jwks_cache_seconds' => (int) env('SSO_JWKS_CACHE_SECONDS', 300),

    // Tolerance for clock differences between this server and the provider, on exp / nbf / iat.
    'clock_skew_seconds' => (int) env('SSO_CLOCK_SKEW_SECONDS', 60),

    // Signature algorithms accepted for the id_token (RSASSA-PKCS1-v1_5 only; "none" and HMAC are never accepted).
    'allowed_algorithms' => ['RS256', 'RS384', 'RS512'],

    // Endpoints of the provider are tenant-configured URLs fetched by the server. By default they must be https and must
    // not resolve to private, loopback or link-local addresses (SSRF). Only for development against a local provider.
    'allow_insecure_endpoints' => (bool) env('SSO_ALLOW_INSECURE_ENDPOINTS', false),

    // Roles a provider mapping or a SCIM request can never grant.
    'forbidden_provisioned_roles' => ['owner'],

    // Role given to a SCIM-provisioned member when the tenant configured none.
    'scim_default_role' => env('SCIM_DEFAULT_ROLE', 'member'),
];
