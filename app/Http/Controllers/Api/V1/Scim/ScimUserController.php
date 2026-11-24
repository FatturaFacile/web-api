<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Scim;

use App\Http\Controllers\Controller;
use App\Services\Auth\Scim\ScimException;
use App\Services\Auth\Scim\ScimUsers;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * SCIM 2.0 `Users` endpoints (RFC 7644) for the identity provider of a tenant, authenticated by the SCIM bearer token of
 * the tenant (see AuthenticateScim). Users are the members of that tenant; see ScimUsers for the decisions behind the
 * behaviour. Responses are `application/scim+json`.
 */
class ScimUserController extends Controller
{
    private const SCIM_CONTENT_TYPE = 'application/scim+json';

    public function __construct(
        private readonly ScimUsers $users,
        private readonly TenantContext $tenantContext,
    ) {}

    #[OA\Get(
        path: '/api/v1/scim/v2/Users',
        operationId: 'scimListUsers',
        description: 'Lists the members of the tenant of the SCIM token. Supports the filters `userName eq "x"` and `externalId eq "x"`, `startIndex` (1-based) and `count` (at most 100).',
        summary: 'SCIM: list users',
        security: [['scimBearerAuth' => []]],
        tags: ['SCIM'],
        parameters: [
            new OA\Parameter(name: 'filter', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'userName eq "anna.rossi@example.com"')),
            new OA\Parameter(name: 'startIndex', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'count', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 0, maximum: 100, default: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'SCIM ListResponse', content: new OA\JsonContent(ref: '#/components/schemas/ScimListResponse')),
            new OA\Response(response: 400, description: 'Unsupported filter', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
            new OA\Response(response: 401, description: 'Missing, invalid, revoked or expired SCIM token', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        return $this->respond(fn (): array => $this->users->list(
            $this->tenantId(),
            $request->query('filter') === null ? null : (string) $request->query('filter'),
            (int) $request->query('startIndex', 1),
            (int) $request->query('count', ScimUsers::MAX_PAGE_SIZE),
        ));
    }

    #[OA\Get(
        path: '/api/v1/scim/v2/Users/{id}',
        operationId: 'scimGetUser',
        description: 'A member of the tenant of the SCIM token. A user of another tenant is a 404.',
        summary: 'SCIM: get a user',
        security: [['scimBearerAuth' => []]],
        tags: ['SCIM'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'SCIM User', content: new OA\JsonContent(ref: '#/components/schemas/ScimUser')),
            new OA\Response(response: 401, description: 'Missing, invalid, revoked or expired SCIM token', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
            new OA\Response(response: 404, description: 'No such member in this tenant', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->users->get($this->tenantId(), $id));
    }

    #[OA\Post(
        path: '/api/v1/scim/v2/Users',
        operationId: 'scimCreateUser',
        description: 'Provisions a member. `userName` is the email address; a `userName` that already belongs to any user is a 409 uniqueness error. The user has no usable password and signs in through the tenant SSO.',
        summary: 'SCIM: create a user',
        security: [['scimBearerAuth' => []]],
        tags: ['SCIM'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ScimUser')),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/ScimUser')),
            new OA\Response(response: 400, description: 'Invalid value', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
            new OA\Response(response: 401, description: 'Missing, invalid, revoked or expired SCIM token', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
            new OA\Response(response: 409, description: 'userName already exists', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        return $this->respond(fn (): array => $this->users->create($this->tenantId(), $request->json()->all(), $this->tokenId($request)), Response::HTTP_CREATED);
    }

    #[OA\Put(
        path: '/api/v1/scim/v2/Users/{id}',
        operationId: 'scimReplaceUser',
        description: 'Replaces the writable attributes (`active`, `externalId`, `displayName`, `name`). `userName` cannot be changed.',
        summary: 'SCIM: replace a user',
        security: [['scimBearerAuth' => []]],
        tags: ['SCIM'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ScimUser')),
        responses: [
            new OA\Response(response: 200, description: 'SCIM User', content: new OA\JsonContent(ref: '#/components/schemas/ScimUser')),
            new OA\Response(response: 400, description: 'Invalid value or immutable attribute', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
            new OA\Response(response: 401, description: 'Missing, invalid, revoked or expired SCIM token', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
            new OA\Response(response: 404, description: 'No such member in this tenant', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
        ]
    )]
    public function replace(Request $request, string $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->users->replace($this->tenantId(), $id, $request->json()->all(), $this->tokenId($request)));
    }

    #[OA\Patch(
        path: '/api/v1/scim/v2/Users/{id}',
        operationId: 'scimPatchUser',
        description: 'SCIM PatchOp (RFC 7644 section 3.5.2): add, replace and remove on `active`, `externalId`, `displayName`, `name.givenName`, `name.familyName`. `active=false` suspends the membership and ends the accesses opened through the tenant SSO.',
        summary: 'SCIM: patch a user',
        security: [['scimBearerAuth' => []]],
        tags: ['SCIM'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ScimPatchOp')),
        responses: [
            new OA\Response(response: 200, description: 'SCIM User', content: new OA\JsonContent(ref: '#/components/schemas/ScimUser')),
            new OA\Response(response: 400, description: 'Invalid operation, path or value', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
            new OA\Response(response: 401, description: 'Missing, invalid, revoked or expired SCIM token', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
            new OA\Response(response: 404, description: 'No such member in this tenant', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
        ]
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->users->patch($this->tenantId(), $id, $request->json()->all(), $this->tokenId($request)));
    }

    #[OA\Delete(
        path: '/api/v1/scim/v2/Users/{id}',
        operationId: 'scimDeleteUser',
        description: 'Deactivates the member: the membership is suspended and the accesses opened through the tenant SSO end. The user record is kept, so a later `active=true` restores the membership. Repeating it is a 204.',
        summary: 'SCIM: deactivate a user',
        security: [['scimBearerAuth' => []]],
        tags: ['SCIM'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Deactivated'),
            new OA\Response(response: 401, description: 'Missing, invalid, revoked or expired SCIM token', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
            new OA\Response(response: 404, description: 'No such member in this tenant', content: new OA\JsonContent(ref: '#/components/schemas/ScimError')),
        ]
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $this->users->deactivate($this->tenantId(), $id, $this->tokenId($request));
        } catch (ScimException $exception) {
            return $this->error($exception);
        }

        return response()->json(null, Response::HTTP_NO_CONTENT, ['Content-Type' => self::SCIM_CONTENT_TYPE]);
    }

    /**
     * @param  callable(): array<string, mixed>  $action
     */
    private function respond(callable $action, int $status = Response::HTTP_OK): JsonResponse
    {
        try {
            return response()->json($action(), $status, ['Content-Type' => self::SCIM_CONTENT_TYPE]);
        } catch (ScimException $exception) {
            return $this->error($exception);
        }
    }

    private function error(ScimException $exception): JsonResponse
    {
        $body = [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'status' => (string) $exception->status,
            'detail' => $exception->getMessage(),
        ];

        if ($exception->scimType !== null) {
            $body['scimType'] = $exception->scimType;
        }

        return response()->json($body, $exception->status, ['Content-Type' => self::SCIM_CONTENT_TYPE]);
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }

    private function tokenId(Request $request): string
    {
        return (string) $request->attributes->get('scim_token_id');
    }
}
