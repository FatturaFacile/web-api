<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Scim;

use App\Services\Auth\Scim\ScimGroups;
use App\Services\Auth\Scim\ScimProvisioningLogger;
use App\Services\Auth\Scim\ScimUsers;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * SCIM 2.0 `Groups` endpoints (RFC 7644) for the identity provider of a tenant, authenticated by the SCIM bearer token of
 * the tenant (see AuthenticateScim). A group change that touches members assigns or revokes the role the tenant mapped
 * to the group; see ScimGroups and ScimGroupRoles for the rules. Responses are `application/scim+json`.
 */
class ScimGroupController extends ScimController
{
    public function __construct(
        private readonly ScimGroups $groups,
        TenantContext $tenantContext,
        ScimProvisioningLogger $provisioningLog,
    ) {
        parent::__construct($tenantContext, $provisioningLog);
    }

    #[OA\Get(
        path: '/api/v1/scim/v2/Groups',
        operationId: 'scimListGroups',
        description: 'Lists the groups of the tenant of the SCIM token with their members. Supports the filters `displayName eq "x"` and `externalId eq "x"`, `startIndex` (1-based) and `count` (at most 100).',
        summary: 'SCIM: list groups',
        security: [['scimBearerAuth' => []]],
        tags: ['SCIM'],
        parameters: [
            new OA\Parameter(name: 'filter', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'displayName eq "Accounting"')),
            new OA\Parameter(name: 'startIndex', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'count', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 0, maximum: 100, default: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'SCIM ListResponse of Group', content: new OA\JsonContent(ref: '#/components/schemas/ScimGroupListResponse')),
            new OA\Response(response: 400, description: 'Unsupported filter', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
            new OA\Response(response: 401, description: 'Missing, invalid, revoked or expired SCIM token', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        return $this->respond(fn (): array => $this->groups->list(
            $this->tenantId(),
            $request->query('filter') === null ? null : (string) $request->query('filter'),
            (int) $request->query('startIndex', 1),
            (int) $request->query('count', ScimUsers::MAX_PAGE_SIZE),
        ));
    }

    #[OA\Get(
        path: '/api/v1/scim/v2/Groups/{id}',
        operationId: 'scimGetGroup',
        description: 'A group of the tenant of the SCIM token, with its members. A group of another tenant is a 404.',
        summary: 'SCIM: get a group',
        security: [['scimBearerAuth' => []]],
        tags: ['SCIM'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'SCIM Group', content: new OA\JsonContent(ref: '#/components/schemas/ScimGroup')),
            new OA\Response(response: 401, description: 'Missing, invalid, revoked or expired SCIM token', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
            new OA\Response(response: 404, description: 'No such group in this tenant', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->groups->get($this->tenantId(), $id));
    }

    #[OA\Post(
        path: '/api/v1/scim/v2/Groups',
        operationId: 'scimCreateGroup',
        description: 'Creates a group. `displayName` is unique within the tenant (409 otherwise); `members` are users already provisioned in the tenant. Members receive the role mapped to the group, if any.',
        summary: 'SCIM: create a group',
        security: [['scimBearerAuth' => []]],
        tags: ['SCIM'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ScimGroup')),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/ScimGroup')),
            new OA\Response(response: 400, description: 'Invalid value or unknown member', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
            new OA\Response(response: 401, description: 'Missing, invalid, revoked or expired SCIM token', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
            new OA\Response(response: 409, description: 'displayName already exists', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        return $this->write($request, 'group', 'create', null, fn (): array => $this->groups->create($this->tenantId(), $request->json()->all(), $this->tokenId($request)), Response::HTTP_CREATED);
    }

    #[OA\Put(
        path: '/api/v1/scim/v2/Groups/{id}',
        operationId: 'scimReplaceGroup',
        description: 'Replaces the group: `displayName`, `externalId` and the whole member list (members that are not sent are removed and lose the role the group granted).',
        summary: 'SCIM: replace a group',
        security: [['scimBearerAuth' => []]],
        tags: ['SCIM'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ScimGroup')),
        responses: [
            new OA\Response(response: 200, description: 'SCIM Group', content: new OA\JsonContent(ref: '#/components/schemas/ScimGroup')),
            new OA\Response(response: 400, description: 'Invalid value or unknown member', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
            new OA\Response(response: 401, description: 'Missing, invalid, revoked or expired SCIM token', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
            new OA\Response(response: 404, description: 'No such group in this tenant', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
            new OA\Response(response: 409, description: 'displayName already exists', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
        ]
    )]
    public function replace(Request $request, string $id): JsonResponse
    {
        return $this->write($request, 'group', 'replace', $id, fn (): array => $this->groups->replace($this->tenantId(), $id, $request->json()->all(), $this->tokenId($request)));
    }

    #[OA\Patch(
        path: '/api/v1/scim/v2/Groups/{id}',
        operationId: 'scimPatchGroup',
        description: 'SCIM PatchOp (RFC 7644 section 3.5.2): add, remove and replace on `members` (also `members[value eq "id"]`), `displayName` and `externalId`. Member changes assign or revoke the mapped role.',
        summary: 'SCIM: patch a group',
        security: [['scimBearerAuth' => []]],
        tags: ['SCIM'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ScimPatchOp')),
        responses: [
            new OA\Response(response: 200, description: 'SCIM Group', content: new OA\JsonContent(ref: '#/components/schemas/ScimGroup')),
            new OA\Response(response: 400, description: 'Invalid operation, path or value', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
            new OA\Response(response: 401, description: 'Missing, invalid, revoked or expired SCIM token', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
            new OA\Response(response: 404, description: 'No such group in this tenant', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
            new OA\Response(response: 409, description: 'displayName already exists', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
        ]
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        return $this->write($request, 'group', 'patch', $id, fn (): array => $this->groups->patch($this->tenantId(), $id, $request->json()->all(), $this->tokenId($request)));
    }

    #[OA\Delete(
        path: '/api/v1/scim/v2/Groups/{id}',
        operationId: 'scimDeleteGroup',
        description: 'Deletes the group. Its members lose the role the group granted (only if it is still the role the mapping gave them); the users themselves are kept.',
        summary: 'SCIM: delete a group',
        security: [['scimBearerAuth' => []]],
        tags: ['SCIM'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 401, description: 'Missing, invalid, revoked or expired SCIM token', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
            new OA\Response(response: 404, description: 'No such group in this tenant', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
        ]
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        return $this->remove($request, 'group', $id, fn () => $this->groups->delete($this->tenantId(), $id, $this->tokenId($request)));
    }
}
