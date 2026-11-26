<?php

declare(strict_types=1);

namespace App\Services\Auth\Scim;

/**
 * The SCIM 2.0 schema documents (RFC 7643 section 7) of the resources this server supports: only the attributes that are
 * really read or written. The EnterpriseUser extension is not offered, because none of its attributes is stored.
 */
final class ScimSchemas
{
    public const SCHEMA_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:Schema';

    public const RESOURCE_TYPE_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:ResourceType';

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [self::user(), self::group()];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $schema) {
            if ($schema['id'] === $id) {
                return $schema;
            }
        }

        return null;
    }

    /**
     * The resource types, in the order of the endpoints.
     *
     * @return list<array<string, mixed>>
     */
    public static function resourceTypes(): array
    {
        return [
            [
                'schemas' => [self::RESOURCE_TYPE_SCHEMA],
                'id' => 'User',
                'name' => 'User',
                'description' => 'A member of the organization.',
                'endpoint' => '/Users',
                'schema' => ScimUsers::USER_SCHEMA,
                'schemaExtensions' => [],
                'meta' => ['resourceType' => 'ResourceType', 'location' => url('/api/v1/scim/v2/ResourceTypes/User')],
            ],
            [
                'schemas' => [self::RESOURCE_TYPE_SCHEMA],
                'id' => 'Group',
                'name' => 'Group',
                'description' => 'A group of members; a group can be mapped to a role of the organization.',
                'endpoint' => '/Groups',
                'schema' => ScimGroups::GROUP_SCHEMA,
                'schemaExtensions' => [],
                'meta' => ['resourceType' => 'ResourceType', 'location' => url('/api/v1/scim/v2/ResourceTypes/Group')],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function user(): array
    {
        return self::document(ScimUsers::USER_SCHEMA, 'User', 'A member of the organization.', [
            self::attribute('userName', 'string', required: true, mutability: 'immutable', uniqueness: 'server', description: 'The email address; it cannot be changed through SCIM.'),
            self::attribute('externalId', 'string', description: 'The identifier of the user at the identity provider.'),
            self::attribute('displayName', 'string', description: 'The name shown for the user.'),
            self::attribute('name', 'complex', description: 'The name of the user.', subAttributes: [
                self::attribute('formatted', 'string'),
                self::attribute('givenName', 'string'),
                self::attribute('familyName', 'string'),
            ]),
            self::attribute('emails', 'complex', multiValued: true, mutability: 'readOnly', description: 'The email address of the user (the userName).', subAttributes: [
                self::attribute('value', 'string', mutability: 'readOnly'),
                self::attribute('type', 'string', mutability: 'readOnly'),
                self::attribute('primary', 'boolean', mutability: 'readOnly'),
            ]),
            self::attribute('roles', 'complex', multiValued: true, mutability: 'writeOnly', description: 'On create the first role that the organization allows is used.', subAttributes: [
                self::attribute('value', 'string'),
            ]),
            self::attribute('active', 'boolean', description: 'false suspends the membership in the organization and ends the accesses opened through its single sign-on.'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function group(): array
    {
        return self::document(ScimGroups::GROUP_SCHEMA, 'Group', 'A group of members of the organization.', [
            self::attribute('displayName', 'string', required: true, uniqueness: 'server', description: 'The name of the group, unique within the organization; the role mapping is by this name.'),
            self::attribute('externalId', 'string', description: 'The identifier of the group at the identity provider.'),
            self::attribute('members', 'complex', multiValued: true, description: 'The users of the group; each must already be provisioned. Members receive the role mapped to the group.', subAttributes: [
                self::attribute('value', 'string', mutability: 'immutable', description: 'The id of the user.'),
                self::attribute('$ref', 'reference', mutability: 'immutable', referenceTypes: ['User']),
                self::attribute('display', 'string', mutability: 'readOnly'),
                self::attribute('type', 'string', mutability: 'readOnly'),
            ]),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $attributes
     * @return array<string, mixed>
     */
    private static function document(string $id, string $name, string $description, array $attributes): array
    {
        return [
            'schemas' => [self::SCHEMA_SCHEMA],
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'attributes' => $attributes,
            'meta' => ['resourceType' => 'Schema', 'location' => url('/api/v1/scim/v2/Schemas/'.$id)],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $subAttributes
     * @param  list<string>  $referenceTypes
     * @return array<string, mixed>
     */
    private static function attribute(
        string $name,
        string $type,
        bool $required = false,
        bool $multiValued = false,
        string $mutability = 'readWrite',
        string $uniqueness = 'none',
        string $description = '',
        array $subAttributes = [],
        array $referenceTypes = [],
    ): array {
        $attribute = [
            'name' => $name,
            'type' => $type,
            'multiValued' => $multiValued,
            'description' => $description,
            'required' => $required,
            'caseExact' => false,
            'mutability' => $mutability,
            'returned' => $mutability === 'writeOnly' ? 'never' : 'default',
            'uniqueness' => $uniqueness,
        ];

        if ($subAttributes !== []) {
            $attribute['subAttributes'] = $subAttributes;
        }

        if ($referenceTypes !== []) {
            $attribute['referenceTypes'] = $referenceTypes;
        }

        return $attribute;
    }
}
