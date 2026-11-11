<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Schemas of the enterprise authentication endpoints: tenant security policy, OpenID Connect single sign-on, SCIM 2.0.
 * SAML 2.0 is supported next to OpenID Connect (onelogin/php-saml).
 */
#[OA\SecurityScheme(
    securityScheme: 'scimBearerAuth',
    type: 'http',
    scheme: 'bearer',
    description: 'SCIM bearer token of the organization (created at POST /api/v1/settings/security/scim/token). It identifies the tenant and opens only the /api/v1/scim/v2 routes.'
)]
#[OA\Schema(
    schema: 'SecurityPolicyPassword',
    properties: [
        new OA\Property(property: 'min_length', type: 'integer', minimum: 8, maximum: 128, example: 12, description: 'The larger of this value and the platform minimum applies.'),
        new OA\Property(property: 'require_mixed_case', type: 'boolean'),
        new OA\Property(property: 'require_numbers', type: 'boolean'),
        new OA\Property(property: 'require_symbols', type: 'boolean'),
        new OA\Property(property: 'history_count', type: 'integer', minimum: 0, maximum: 24, description: 'How many previous passwords cannot be reused (0 = no check).'),
        new OA\Property(property: 'max_age_days', type: 'integer', minimum: 1, maximum: 730, nullable: true, description: 'Password older than this cannot sign in until it is reset (null = never expires).'),
    ]
)]
#[OA\Schema(
    schema: 'SecurityPolicyLockout',
    properties: [
        new OA\Property(property: 'max_attempts', type: 'integer', description: '0 = no lockout, otherwise 3 to 20 wrong passwords lock the account.'),
        new OA\Property(property: 'minutes', type: 'integer', minimum: 1, maximum: 1440),
    ]
)]
#[OA\Schema(
    schema: 'SecurityPolicySession',
    properties: [
        new OA\Property(property: 'idle_timeout_minutes', type: 'integer', minimum: 5, maximum: 1440, nullable: true),
        new OA\Property(property: 'max_concurrent_sessions', type: 'integer', minimum: 1, maximum: 50, nullable: true, description: 'When a new access is opened beyond the limit, the least recently used ones are revoked.'),
    ]
)]
#[OA\Schema(
    schema: 'SecurityPolicyTwoFactor',
    properties: [
        new OA\Property(property: 'require_for', type: 'string', enum: ['none', 'all', 'roles']),
        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string'), description: 'Roles that must use two factors when require_for is `roles`.'),
    ]
)]
#[OA\Schema(
    schema: 'SecurityPolicy',
    description: 'The per-tenant security policy (what is enforced: the platform defaults tightened by the tenant).',
    properties: [
        new OA\Property(property: 'password', ref: '#/components/schemas/SecurityPolicyPassword'),
        new OA\Property(property: 'lockout', ref: '#/components/schemas/SecurityPolicyLockout'),
        new OA\Property(property: 'session', ref: '#/components/schemas/SecurityPolicySession'),
        new OA\Property(property: 'two_factor', ref: '#/components/schemas/SecurityPolicyTwoFactor'),
        new OA\Property(property: 'sso_only', type: 'boolean', description: 'Password sign-in is refused; members sign in through the tenant SSO. Needs a ready SSO provider.'),
        new OA\Property(property: 'sso_ready', type: 'boolean', readOnly: true),
    ]
)]
#[OA\Schema(
    schema: 'SsoProviderInput',
    required: ['name', 'issuer', 'redirect_uri', 'client_id', 'allowed_email_domains'],
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Corporate Entra ID'),
        new OA\Property(property: 'issuer', type: 'string', format: 'uri', example: 'https://login.example.com/tenant-id/v2.0'),
        new OA\Property(property: 'use_discovery', type: 'boolean', default: true, description: 'Read the endpoints from <issuer>/.well-known/openid-configuration. When false the three endpoints below are required.'),
        new OA\Property(property: 'authorization_endpoint', type: 'string', format: 'uri', nullable: true),
        new OA\Property(property: 'token_endpoint', type: 'string', format: 'uri', nullable: true),
        new OA\Property(property: 'jwks_uri', type: 'string', format: 'uri', nullable: true),
        new OA\Property(property: 'redirect_uri', type: 'string', format: 'uri', description: 'Where the identity provider sends the browser back; the client then posts code and state to /api/v1/auth/sso/callback.'),
        new OA\Property(property: 'client_id', type: 'string'),
        new OA\Property(property: 'client_secret', type: 'string', nullable: true, writeOnly: true, description: 'Stored encrypted, never returned. Omit on update to keep the current one; empty for a public client.'),
        new OA\Property(property: 'scopes', type: 'string', example: 'openid email profile'),
        new OA\Property(property: 'allowed_email_domains', type: 'array', items: new OA\Items(type: 'string', example: 'example.com')),
        new OA\Property(property: 'role_claim', type: 'string', nullable: true, example: 'groups'),
        new OA\Property(property: 'role_mapping', type: 'object', nullable: true, additionalProperties: new OA\AdditionalProperties(type: 'string'), example: ['erp-accounting' => 'accountant'], description: 'Claim value to role; the first match in this order wins. The owner role is never granted.'),
        new OA\Property(property: 'default_role', type: 'string', nullable: true, example: 'member'),
        new OA\Property(property: 'sync_role_on_login', type: 'boolean', default: false),
        new OA\Property(property: 'jit_provisioning', type: 'boolean', default: false),
        new OA\Property(property: 'enabled', type: 'boolean', default: false),
    ]
)]
#[OA\Schema(
    schema: 'SsoProvider',
    description: 'The OpenID Connect provider of the tenant. The client secret is never returned.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'issuer', type: 'string'),
        new OA\Property(property: 'authorization_endpoint', type: 'string', nullable: true),
        new OA\Property(property: 'token_endpoint', type: 'string', nullable: true),
        new OA\Property(property: 'jwks_uri', type: 'string', nullable: true),
        new OA\Property(property: 'redirect_uri', type: 'string'),
        new OA\Property(property: 'client_id', type: 'string'),
        new OA\Property(property: 'has_client_secret', type: 'boolean'),
        new OA\Property(property: 'scopes', type: 'string'),
        new OA\Property(property: 'allowed_email_domains', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'role_claim', type: 'string', nullable: true),
        new OA\Property(property: 'role_mapping', type: 'object', nullable: true),
        new OA\Property(property: 'default_role', type: 'string', nullable: true),
        new OA\Property(property: 'sync_role_on_login', type: 'boolean'),
        new OA\Property(property: 'jit_provisioning', type: 'boolean'),
        new OA\Property(property: 'enabled', type: 'boolean'),
        new OA\Property(property: 'ready', type: 'boolean', description: 'Enabled and with the three endpoints.'),
        new OA\Property(property: 'discovered_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'SsoStartRequest',
    required: ['tenant_id'],
    properties: [new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid')]
)]
#[OA\Schema(
    schema: 'SsoCallbackRequest',
    required: ['code', 'state'],
    properties: [
        new OA\Property(property: 'code', type: 'string', description: 'Authorization code returned by the identity provider.'),
        new OA\Property(property: 'state', type: 'string', description: 'The state of the started login; it is valid once, for a few minutes.'),
        new OA\Property(property: 'token_mode', type: 'boolean', default: false, description: 'Ask for a Bearer token instead of the cookie session. Accepted only when the server enables the token mode (auth.token_mode.enabled); otherwise a 422.'),
    ]
)]
#[OA\Schema(
    schema: 'ScimToken',
    properties: [
        new OA\Property(property: 'configured', type: 'boolean'),
        new OA\Property(property: 'token_hint', type: 'string', nullable: true, example: 'a1b2', description: 'Last 4 characters of the token.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'last_used_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'token', type: 'string', nullable: true, description: 'The plain token: present only in the response that creates it.'),
    ]
)]
#[OA\Schema(
    schema: 'ScimUser',
    description: 'SCIM 2.0 User (RFC 7643 section 4.1), the subset supported here.',
    properties: [
        new OA\Property(property: 'schemas', type: 'array', items: new OA\Items(type: 'string'), example: ['urn:ietf:params:scim:schemas:core:2.0:User']),
        new OA\Property(property: 'id', type: 'string', format: 'uuid', readOnly: true),
        new OA\Property(property: 'externalId', type: 'string'),
        new OA\Property(property: 'userName', type: 'string', format: 'email'),
        new OA\Property(property: 'name', type: 'object', properties: [
            new OA\Property(property: 'givenName', type: 'string'),
            new OA\Property(property: 'familyName', type: 'string'),
            new OA\Property(property: 'formatted', type: 'string'),
        ]),
        new OA\Property(property: 'displayName', type: 'string'),
        new OA\Property(property: 'emails', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'object'), description: 'On create: the first valid role is used.'),
        new OA\Property(property: 'active', type: 'boolean'),
        new OA\Property(property: 'meta', type: 'object', readOnly: true),
    ]
)]
#[OA\Schema(
    schema: 'ScimListResponse',
    properties: [
        new OA\Property(property: 'schemas', type: 'array', items: new OA\Items(type: 'string'), example: ['urn:ietf:params:scim:api:messages:2.0:ListResponse']),
        new OA\Property(property: 'totalResults', type: 'integer'),
        new OA\Property(property: 'startIndex', type: 'integer'),
        new OA\Property(property: 'itemsPerPage', type: 'integer'),
        new OA\Property(property: 'Resources', type: 'array', items: new OA\Items(ref: '#/components/schemas/ScimUser')),
    ]
)]
#[OA\Schema(
    schema: 'ScimPatchOp',
    required: ['schemas', 'Operations'],
    properties: [
        new OA\Property(property: 'schemas', type: 'array', items: new OA\Items(type: 'string'), example: ['urn:ietf:params:scim:api:messages:2.0:PatchOp']),
        new OA\Property(property: 'Operations', type: 'array', items: new OA\Items(
            type: 'object',
            properties: [
                new OA\Property(property: 'op', type: 'string', enum: ['add', 'replace', 'remove']),
                new OA\Property(property: 'path', type: 'string', example: 'active'),
                new OA\Property(property: 'value', example: false),
            ]
        )),
    ]
)]
#[OA\Schema(
    schema: 'ScimError',
    description: 'SCIM error (RFC 7644 section 3.12), served as application/scim+json.',
    properties: [
        new OA\Property(property: 'schemas', type: 'array', items: new OA\Items(type: 'string'), example: ['urn:ietf:params:scim:api:messages:2.0:Error']),
        new OA\Property(property: 'status', type: 'string', example: '409'),
        new OA\Property(property: 'scimType', type: 'string', example: 'uniqueness', nullable: true),
        new OA\Property(property: 'detail', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'SamlProviderInput',
    required: ['name', 'allowed_email_domains'],
    description: 'Give one source of the identity provider: idp_metadata_url, idp_metadata_xml, or idp_entity_id with idp_sso_url and idp_certificates.',
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Corporate SAML IdP'),
        new OA\Property(property: 'idp_metadata_url', type: 'string', format: 'uri', nullable: true),
        new OA\Property(property: 'idp_metadata_xml', type: 'string', nullable: true, description: 'Metadata XML of the identity provider.'),
        new OA\Property(property: 'idp_entity_id', type: 'string', nullable: true),
        new OA\Property(property: 'idp_sso_url', type: 'string', format: 'uri', nullable: true),
        new OA\Property(property: 'idp_slo_url', type: 'string', format: 'uri', nullable: true),
        new OA\Property(property: 'idp_certificates', type: 'array', items: new OA\Items(type: 'string'), nullable: true, description: 'X.509 signing certificates (PEM or bare base64).'),
        new OA\Property(property: 'email_attribute', type: 'string', nullable: true, description: 'Attribute with the email; common names and the NameID are tried when empty.'),
        new OA\Property(property: 'name_attribute', type: 'string', nullable: true),
        new OA\Property(property: 'role_attribute', type: 'string', nullable: true, example: 'groups'),
        new OA\Property(property: 'role_mapping', type: 'object', nullable: true, additionalProperties: new OA\AdditionalProperties(type: 'string'), example: ['erp-accounting' => 'accountant']),
        new OA\Property(property: 'default_role', type: 'string', nullable: true),
        new OA\Property(property: 'sync_role_on_login', type: 'boolean', default: false),
        new OA\Property(property: 'jit_provisioning', type: 'boolean', default: false),
        new OA\Property(property: 'allowed_email_domains', type: 'array', items: new OA\Items(type: 'string', example: 'example.com')),
        new OA\Property(property: 'sp_private_key', type: 'string', nullable: true, writeOnly: true, description: 'Private key the service provider signs requests with; stored encrypted, never returned.'),
        new OA\Property(property: 'sp_certificate', type: 'string', nullable: true, description: 'Certificate of that key, published in the metadata.'),
        new OA\Property(property: 'enabled', type: 'boolean', default: false),
    ]
)]
#[OA\Schema(
    schema: 'SamlProvider',
    description: 'The SAML 2.0 provider of the tenant, with the service provider URLs to give to the identity provider.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'idp_entity_id', type: 'string'),
        new OA\Property(property: 'idp_sso_url', type: 'string'),
        new OA\Property(property: 'idp_slo_url', type: 'string', nullable: true),
        new OA\Property(property: 'idp_certificates_count', type: 'integer'),
        new OA\Property(property: 'idp_metadata_url', type: 'string', nullable: true),
        new OA\Property(property: 'metadata_refreshed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'email_attribute', type: 'string', nullable: true),
        new OA\Property(property: 'name_attribute', type: 'string', nullable: true),
        new OA\Property(property: 'role_attribute', type: 'string', nullable: true),
        new OA\Property(property: 'role_mapping', type: 'object', nullable: true),
        new OA\Property(property: 'default_role', type: 'string', nullable: true),
        new OA\Property(property: 'sync_role_on_login', type: 'boolean'),
        new OA\Property(property: 'jit_provisioning', type: 'boolean'),
        new OA\Property(property: 'allowed_email_domains', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'has_sp_signing_key', type: 'boolean'),
        new OA\Property(property: 'enabled', type: 'boolean'),
        new OA\Property(property: 'ready', type: 'boolean'),
        new OA\Property(property: 'sp', type: 'object', properties: [
            new OA\Property(property: 'entity_id', type: 'string'),
            new OA\Property(property: 'acs_url', type: 'string'),
            new OA\Property(property: 'slo_url', type: 'string'),
            new OA\Property(property: 'metadata_url', type: 'string'),
        ]),
    ]
)]
class EnterpriseAuthSchemas {}
