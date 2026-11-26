<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ListSecurityAuditRequest;
use App\Http\Resources\Auth\SecurityAuditEventResource;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\Auth\Security\SecurityAuditCatalog;
use App\Services\Auth\Security\SecurityAuditReader;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The security audit viewer: the authentication and security events of the active tenant from the immutable audit ledger
 * (sign-ins and failures, passwords, second factors, passkeys, sessions, lockouts, single sign-on, SCIM, policy changes).
 * Read only; reserved to owners and administrators (module `system`).
 */
class SecurityAuditController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SecurityAuditReader $reader,
    ) {}

    #[OA\Get(
        path: '/api/v1/settings/security/audit',
        operationId: 'listSecurityAudit',
        description: 'Paginated authentication and security events of the active tenant, newest first. Filters: from and to (dates or date-times), user_id, event_type (one type or a comma separated list, see /settings/security/audit/event-types), status (success|failure), auth_method (password|sso|sso_saml|sso_oidc|passkey). Each item has the structured fields id, occurred_at, tenant_id, user_id, event_type, status, ip_address, user_agent, auth_method, mfa_method and reason.',
        summary: 'Security audit log',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'event_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'LOGIN_LOCKED,SSO_LOGIN_FAILED')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['success', 'failure'])),
            new OA\Parameter(name: 'auth_method', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['password', 'sso', 'sso_saml', 'sso_oidc', 'passkey'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 5, maximum: 100, default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of events', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SecurityAuditEvent'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function index(ListSecurityAuditRequest $request): JsonResponse
    {
        $page = $this->reader->paginate((string) $this->activeTenant()->id, $request->safe()->only(['from', 'to', 'user_id', 'event_type', 'status', 'auth_method']), (int) $request->integer('per_page', 25));
        $users = $this->reader->usersOf($page->getCollection());

        return $this->successResponse(
            data: $page->getCollection()->map(fn ($entry): array => (new SecurityAuditEventResource($entry, $users))->resolve($request))->values()->all(),
            message: __('advanced_auth.messages.security_audit_retrieved'),
            meta: [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        );
    }

    #[OA\Get(
        path: '/api/v1/settings/security/audit/event-types',
        operationId: 'listSecurityAuditEventTypes',
        description: 'The event types the security audit log can contain, with the translation key of their label (an API-served dictionary key), their category and whether they denote a success or a failure.',
        summary: 'Security audit event types',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        responses: [
            new OA\Response(response: 200, description: 'The event types', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [
                new OA\Property(property: 'event_type', type: 'string', example: 'LOGIN_LOCKED'),
                new OA\Property(property: 'translation_key', type: 'string', example: 'security.audit.event.login_locked'),
                new OA\Property(property: 'category', type: 'string', example: 'lockout'),
                new OA\Property(property: 'status', type: 'string', enum: ['success', 'failure']),
            ]))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function eventTypes(): JsonResponse
    {
        return $this->successResponse(data: SecurityAuditCatalog::eventTypes());
    }

    private function activeTenant(): Tenant
    {
        $tenant = $this->tenantContext->getTenant();
        abort_if($tenant === null, Response::HTTP_BAD_REQUEST, 'Missing tenant context.');

        return $tenant;
    }
}
