<?php

declare(strict_types=1);

namespace Modules\Platform\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Mod 004 IAM endpoints: role console, permission catalog, ABAC rules, field policies, role assignment and the own
 * permission snapshot. Each route is decided by a permission key (`can:` middleware, deny by default).
 */
#[OA\Tag(name: 'Identity', description: 'Granular access control: roles, permission keys (<module>.<resource>.<action>), ABAC rules, field policies (visible, masked, hidden) and role assignments.')]
#[OA\Get(path: '/api/v1/profile/permissions', operationId: 'getProfilePermissions', summary: 'Effective permissions and field policies of the signed-in user', security: [['bearerAuth' => []]], tags: ['Identity'], responses: [new OA\Response(response: 200, description: 'Snapshot'), new OA\Response(response: 401, description: 'Unauthenticated')])]
#[OA\Get(path: '/api/v1/settings/permissions/catalog', operationId: 'getPermissionCatalog', summary: 'Permission catalog by module and resource', security: [['bearerAuth' => []]], tags: ['Identity'], responses: [new OA\Response(response: 200, description: 'Catalog'), new OA\Response(response: 403, description: 'permission_denied')])]
#[OA\Get(path: '/api/v1/settings/roles', operationId: 'listIdentityRoles', summary: 'List system and custom roles', security: [['bearerAuth' => []]], tags: ['Identity'], responses: [new OA\Response(response: 200, description: 'Roles'), new OA\Response(response: 403, description: 'permission_denied')])]
#[OA\Post(path: '/api/v1/settings/roles', operationId: 'createIdentityRole', summary: 'Create a custom role', security: [['bearerAuth' => []]], tags: ['Identity'], responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 409, description: 'Unknown permission'), new OA\Response(response: 422, description: 'Validation error')])]
#[OA\Get(path: '/api/v1/settings/roles/{role}', operationId: 'getIdentityRole', summary: 'Show a role with permissions, ABAC rules and field policies (ETag)', security: [['bearerAuth' => []]], tags: ['Identity'], responses: [new OA\Response(response: 200, description: 'Role'), new OA\Response(response: 404, description: 'Not found')])]
#[OA\Put(path: '/api/v1/settings/roles/{role}', operationId: 'updateIdentityRole', summary: 'Update a custom role (If-Match supported)', security: [['bearerAuth' => []]], tags: ['Identity'], responses: [new OA\Response(response: 200, description: 'Updated'), new OA\Response(response: 409, description: 'System role'), new OA\Response(response: 412, description: 'Precondition failed')])]
#[OA\Delete(path: '/api/v1/settings/roles/{role}', operationId: 'deleteIdentityRole', summary: 'Delete a custom role', security: [['bearerAuth' => []]], tags: ['Identity'], responses: [new OA\Response(response: 200, description: 'Deleted'), new OA\Response(response: 409, description: 'System role or role in use')])]
#[OA\Post(path: '/api/v1/settings/roles/{role}/clone', operationId: 'cloneIdentityRole', summary: 'Clone a role with its rules and policies', security: [['bearerAuth' => []]], tags: ['Identity'], responses: [new OA\Response(response: 201, description: 'Cloned')])]
#[OA\Get(path: '/api/v1/settings/roles/{role}/abac-rules', operationId: 'getIdentityRoleAbacRules', summary: 'ABAC rules of a role', security: [['bearerAuth' => []]], tags: ['Identity'], responses: [new OA\Response(response: 200, description: 'Rules')])]
#[OA\Put(path: '/api/v1/settings/roles/{role}/abac-rules', operationId: 'updateIdentityRoleAbacRules', summary: 'Replace the ABAC rules of a role', security: [['bearerAuth' => []]], tags: ['Identity'], responses: [new OA\Response(response: 200, description: 'Saved'), new OA\Response(response: 422, description: 'Unsupported attribute or operator')])]
#[OA\Get(path: '/api/v1/settings/roles/{role}/field-policies', operationId: 'getIdentityRoleFieldPolicies', summary: 'Field policies of a role', security: [['bearerAuth' => []]], tags: ['Identity'], responses: [new OA\Response(response: 200, description: 'Policies')])]
#[OA\Put(path: '/api/v1/settings/roles/{role}/field-policies', operationId: 'updateIdentityRoleFieldPolicies', summary: 'Replace the field policies of a role', security: [['bearerAuth' => []]], tags: ['Identity'], responses: [new OA\Response(response: 200, description: 'Saved')])]
#[OA\Put(path: '/api/v1/settings/users/{user}/roles', operationId: 'assignIdentityUserRoles', summary: 'Replace the roles of a user (optionally per company)', security: [['bearerAuth' => []]], tags: ['Identity'], responses: [new OA\Response(response: 200, description: 'Assigned'), new OA\Response(response: 403, description: 'Privilege escalation or self change'), new OA\Response(response: 409, description: 'Last owner or segregation of duties conflict')])]
final class IdentityOpenApi {}
