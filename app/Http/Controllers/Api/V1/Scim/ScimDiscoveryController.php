<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Scim;

use App\Http\Controllers\Controller;
use App\Services\Auth\Scim\ScimUsers;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * SCIM 2.0 discovery (RFC 7644 section 4): what this server supports, so an identity provider can configure itself.
 */
class ScimDiscoveryController extends Controller
{
    #[OA\Get(
        path: '/api/v1/scim/v2/ServiceProviderConfig',
        operationId: 'scimServiceProviderConfig',
        description: 'The SCIM features of this server: PATCH supported, filter limited to one equality, no bulk, no sort, no ETag, no password change, bearer-token authentication.',
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
        description: 'The resource types of this server: only User.',
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
        return response()->json([
            'schemas' => [ScimUsers::LIST_SCHEMA],
            'totalResults' => 1,
            'startIndex' => 1,
            'itemsPerPage' => 1,
            'Resources' => [[
                'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'],
                'id' => 'User',
                'name' => 'User',
                'endpoint' => '/Users',
                'schema' => ScimUsers::USER_SCHEMA,
                'meta' => ['resourceType' => 'ResourceType', 'location' => url('/api/v1/scim/v2/ResourceTypes/User')],
            ]],
        ], 200, ['Content-Type' => 'application/scim+json']);
    }
}
