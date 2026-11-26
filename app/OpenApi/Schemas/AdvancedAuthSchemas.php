<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Schemas of the advanced authentication endpoints: SCIM groups, group to role mappings and the provisioning log, the
 * compromised-password and password rules endpoints, and the security audit viewer.
 */
#[OA\Schema(
    schema: 'ScimGroup',
    description: 'SCIM 2.0 Group (RFC 7643 section 4.2). `members` hold the ids of users already provisioned in the tenant.',
    properties: [
        new OA\Property(property: 'schemas', type: 'array', items: new OA\Items(type: 'string'), example: ['urn:ietf:params:scim:schemas:core:2.0:Group']),
        new OA\Property(property: 'id', type: 'string', format: 'uuid', readOnly: true),
        new OA\Property(property: 'externalId', type: 'string'),
        new OA\Property(property: 'displayName', type: 'string', example: 'ERP Accounting'),
        new OA\Property(property: 'members', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'value', type: 'string', format: 'uuid'),
            new OA\Property(property: 'display', type: 'string', readOnly: true),
            new OA\Property(property: '$ref', type: 'string', readOnly: true),
            new OA\Property(property: 'type', type: 'string', readOnly: true, example: 'User'),
        ])),
        new OA\Property(property: 'meta', type: 'object', readOnly: true),
    ]
)]
#[OA\Schema(
    schema: 'ScimGroupListResponse',
    properties: [
        new OA\Property(property: 'schemas', type: 'array', items: new OA\Items(type: 'string'), example: ['urn:ietf:params:scim:api:messages:2.0:ListResponse']),
        new OA\Property(property: 'totalResults', type: 'integer'),
        new OA\Property(property: 'startIndex', type: 'integer'),
        new OA\Property(property: 'itemsPerPage', type: 'integer'),
        new OA\Property(property: 'Resources', type: 'array', items: new OA\Items(ref: '#/components/schemas/ScimGroup')),
    ]
)]
#[OA\Schema(
    schema: 'ScimGroupMapping',
    description: 'The role the members of a SCIM group (by display name) get in the tenant.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'group_name', type: 'string', example: 'ERP Accounting'),
        new OA\Property(property: 'role', type: 'string', example: 'accountant'),
        new OA\Property(property: 'member_count', type: 'integer', nullable: true, description: 'Members of the group now; null while the identity provider has not pushed the group.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ScimProvisioningLog',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'resource', type: 'string', enum: ['user', 'group']),
        new OA\Property(property: 'operation', type: 'string', enum: ['create', 'replace', 'patch', 'delete']),
        new OA\Property(property: 'status', type: 'string', enum: ['success', 'failure']),
        new OA\Property(property: 'resource_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'resource_name', type: 'string', nullable: true),
        new OA\Property(property: 'error', type: 'string', nullable: true),
        new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ScimSyncStatus',
    properties: [
        new OA\Property(property: 'last_sync_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'last_status', type: 'string', enum: ['success', 'failure'], nullable: true),
        new OA\Property(property: 'users_provisioned', type: 'integer'),
        new OA\Property(property: 'groups_provisioned', type: 'integer'),
        new OA\Property(property: 'last_error', type: 'object', nullable: true, properties: [
            new OA\Property(property: 'message', type: 'string'),
            new OA\Property(property: 'resource', type: 'string'),
            new OA\Property(property: 'operation', type: 'string'),
            new OA\Property(property: 'at', type: 'string', format: 'date-time'),
        ]),
    ]
)]
#[OA\Schema(
    schema: 'PasswordPolicyRules',
    description: 'The password rules in force. `rules` lists the codes a checklist contains: min_length, mixed_case, numbers, symbols, reused, breached.',
    properties: [
        new OA\Property(property: 'min_length', type: 'integer'),
        new OA\Property(property: 'require_mixed_case', type: 'boolean'),
        new OA\Property(property: 'require_numbers', type: 'boolean'),
        new OA\Property(property: 'require_symbols', type: 'boolean'),
        new OA\Property(property: 'check_breached', type: 'boolean'),
        new OA\Property(property: 'history_count', type: 'integer', nullable: true, description: 'Only for the signed-in user.'),
        new OA\Property(property: 'max_age_days', type: 'integer', nullable: true, description: 'Only for the signed-in user.'),
        new OA\Property(property: 'rules', type: 'array', items: new OA\Items(type: 'string')),
    ]
)]
#[OA\Schema(
    schema: 'PasswordPolicyCheck',
    properties: [
        new OA\Property(property: 'rules', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'code', type: 'string', example: 'min_length'),
            new OA\Property(property: 'passed', type: 'boolean'),
            new OA\Property(property: 'message', type: 'string'),
        ])),
        new OA\Property(property: 'acceptable', type: 'boolean', description: 'Every rule passes and the password is not empty.'),
        new OA\Property(property: 'strength', type: 'integer', minimum: 0, maximum: 4),
    ]
)]
#[OA\Schema(
    schema: 'SecurityAuditEvent',
    description: 'An authentication or security event of the audit ledger of the tenant.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'user_id', type: 'string', format: 'uuid', nullable: true, description: 'The user the event is about.'),
        new OA\Property(property: 'actor_user_id', type: 'string', format: 'uuid', nullable: true, description: 'Who did it (an administrator for an unlock).'),
        new OA\Property(property: 'user', type: 'object', nullable: true, description: 'id, name and email of the user, when the account still exists.'),
        new OA\Property(property: 'event_type', type: 'string', example: 'LOGIN_LOCKED'),
        new OA\Property(property: 'status', type: 'string', enum: ['success', 'failure']),
        new OA\Property(property: 'ip_address', type: 'string', nullable: true),
        new OA\Property(property: 'user_agent', type: 'string', nullable: true),
        new OA\Property(property: 'auth_method', type: 'string', nullable: true, enum: ['password', 'sso', 'sso_saml', 'sso_oidc', 'passkey']),
        new OA\Property(property: 'mfa_method', type: 'string', nullable: true),
        new OA\Property(property: 'reason', type: 'string', nullable: true),
    ]
)]
final class AdvancedAuthSchemas {}
