<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Scim;

use App\Http\Controllers\Controller;
use App\Services\Auth\Scim\ScimSchemas;
use App\Services\Auth\Scim\ScimUsers;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * SCIM 2.0 discovery (RFC 7644 section 4): what this server supports, so an identity provider can configure itself.
 */
class ScimDiscoveryController extends Controller
{
    #[OA\Get(
        path: '/api/v1/scim/v2/ServiceProviderConfig',
        operationId: 'scimServiceProviderConfig',
        description: 'The SCIM features of this server: PATCH supported, filter limited to one equality (Users and Groups), no bulk, no sort, no ETag, no password change, bearer-token authentication.',
        summary: 'SCIM: service provider configuration',
        security: [['scimBearerAuth' => []]],
        tags: ['SCIM'],
        responses: [
            new OA\Response(response: 200, description: 'ServiceProviderConfig'),
            new OA\Response(response: 401, description: 'Missing, invalid, revoked or expired SCIM token', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
        ]
    )]
    public function serviceProviderConfig(): JsonResponse
    {
        return response()->json([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            'patch' => ['supported' => true],
            'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter' => ['supported' => true, 'maxResults' => ScimUsers::MAX_PAGE_SIZE],
            'changePassword' => ['supported' => false],
            'sort' => ['supported' => false],
            'etag' => ['supported' => false],
            'authenticationSchemes' => [[
                'type' => 'oauthbearertoken',
                'name' => 'Bearer token',
                'description' => 'A SCIM bearer token of the organization.',
                'primary' => true,
            ]],
            'meta' => ['resourceType' => 'ServiceProviderConfig', 'location' => url('/api/v1/scim/v2/ServiceProviderConfig')],
        ], 200, ['Content-Type' => 'application/scim+json']);
    }

    #[OA\Get(
        path: '/api/v1/scim/v2/ResourceTypes',
        operationId: 'scimResourceTypes',
        description: 'The resource types of this server: User and Group.',
        summary: 'SCIM: resource types',
        security: [['scimBearerAuth' => []]],
        tags: ['SCIM'],
        responses: [
            new OA\Response(response: 200, description: 'ListResponse of ResourceType'),
            new OA\Response(response: 401, description: 'Missing, invalid, revoked or expired SCIM token', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
        ]
    )]
    public function resourceTypes(): JsonResponse
    {
        return $this->list(ScimSchemas::resourceTypes());
    }

    #[OA\Get(
        path: '/api/v1/scim/v2/Schemas',
        operationId: 'scimSchemas',
        description: 'The schema documents (RFC 7643 section 7) of the User and Group resources, with the attributes this server reads and writes.',
        summary: 'SCIM: schemas',
        security: [['scimBearerAuth' => []]],
        tags: ['SCIM'],
        responses: [
            new OA\Response(response: 200, description: 'ListResponse of Schema'),
            new OA\Response(response: 401, description: 'Missing, invalid, revoked or expired SCIM token', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
        ]
    )]
    public function schemas(): JsonResponse
    {
        return $this->list(ScimSchemas::all());
    }

    #[OA\Get(
        path: '/api/v1/scim/v2/Schemas/{id}',
        operationId: 'scimSchema',
        description: 'One schema document, by its URN (for example urn:ietf:params:scim:schemas:core:2.0:Group).',
        summary: 'SCIM: one schema',
        security: [['scimBearerAuth' => []]],
        tags: ['SCIM'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Schema'),
            new OA\Response(response: 401, description: 'Missing, invalid, revoked or expired SCIM token', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
            new OA\Response(response: 404, description: 'No such schema', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
        ]
    )]
    public function schema(string $id): JsonResponse
    {
        $schema = ScimSchemas::find($id);

        if ($schema === null) {
            return response()->json([
                'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
                'status' => '404',
                'detail' => 'Schema not found.',
            ], Response::HTTP_NOT_FOUND, ['Content-Type' => 'application/scim+json']);
        }

        return response()->json($schema, Response::HTTP_OK, ['Content-Type' => 'application/scim+json']);
    }

    /**
     * @param  list<array<string, mixed>>  $resources
     */
    private function list(array $resources): JsonResponse
    {
        return response()->json([
            'schemas' => [ScimUsers::LIST_SCHEMA],
            'totalResults' => count($resources),
            'startIndex' => 1,
            'itemsPerPage' => count($resources),
            'Resources' => $resources,
        ], Response::HTTP_OK, ['Content-Type' => 'application/scim+json']);
    }
}
