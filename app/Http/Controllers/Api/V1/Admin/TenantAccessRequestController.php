<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Auth\DecideTenantAccessRequest as DecideAction;
use App\Exceptions\TenantAccessRequestNotDecidableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DecideTenantAccessRequest;
use App\Http\Resources\TenantAccessRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Models\TenantAccessRequest;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform administrators decide who gets access to which tenant after a self-registration.
 */
class TenantAccessRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DecideAction $decisions) {}

    #[OA\Get(
        path: '/api/v1/admin/tenant-access-requests',
        operationId: 'listTenantAccessRequests',
        summary: 'List the access requests of self-registered users',
        security: [['bearerAuth' => []]],
        tags: ['Platform Administration'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'pending', enum: ['pending', 'approved', 'rejected'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Requests retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Not a platform administrator', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', TenantAccessRequest::PENDING);

        $requests = TenantAccessRequest::query()
            ->with(['user', 'tenant'])
            ->where('status', $status)
            ->whereHas('user', fn ($query) => $query->whereNotNull('email_verified_at'))
            ->oldest()
            ->paginate(min(100, max(5, (int) $request->query('per_page', 25))));

        return $this->successResponse(
            data: TenantAccessRequestResource::collection($requests->getCollection()),
            message: __('registration.messages.requests_listed'),
            meta: ['current_page' => $requests->currentPage(), 'last_page' => $requests->lastPage(), 'per_page' => $requests->perPage(), 'total' => $requests->total()],
        );
    }

    #[OA\Post(
        path: '/api/v1/admin/tenant-access-requests/{id}/approve',
        operationId: 'approveTenantAccessRequest',
        description: 'Confirm the matched tenant, or create the requested company when the registration did not match any.',
        summary: 'Approve an access request',
        security: [['bearerAuth' => []]],
        tags: ['Platform Administration'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [new OA\Property(property: 'role', type: 'string', enum: ['owner', 'admin', 'accountant', 'technician', 'member'])])),
        responses: [
            new OA\Response(response: 200, description: 'Request approved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Not a platform administrator', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Already decided, or email address not confirmed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function approve(DecideTenantAccessRequest $request, string $id): JsonResponse
    {
        return $this->decided(fn (): TenantAccessRequest => $this->decisions->approve($id, $request->user(), $request->validated('role')));
    }

    #[OA\Post(
        path: '/api/v1/admin/tenant-access-requests/{id}/move',
        operationId: 'moveTenantAccessRequest',
        summary: 'Give the user access to a different existing tenant',
        security: [['bearerAuth' => []]],
        tags: ['Platform Administration'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['tenant_id'], properties: [
                new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'role', type: 'string', enum: ['owner', 'admin', 'accountant', 'technician', 'member']),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Request approved on the chosen tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Not a platform administrator', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Already decided, or email address not confirmed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function move(DecideTenantAccessRequest $request, string $id): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($request->validated('tenant_id'));

        return $this->decided(fn (): TenantAccessRequest => $this->decisions->move($id, $request->user(), $tenant, $request->validated('role')));
    }

    #[OA\Post(
        path: '/api/v1/admin/tenant-access-requests/{id}/reject',
        operationId: 'rejectTenantAccessRequest',
        summary: 'Reject an access request',
        security: [['bearerAuth' => []]],
        tags: ['Platform Administration'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 500)])),
        responses: [
            new OA\Response(response: 200, description: 'Request rejected', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Not a platform administrator', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Already decided, or email address not confirmed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function reject(DecideTenantAccessRequest $request, string $id): JsonResponse
    {
        return $this->decided(fn (): TenantAccessRequest => $this->decisions->reject($id, $request->user(), $request->validated('reason')));
    }

    /**
     * @param  Closure(): TenantAccessRequest  $decision
     */
    private function decided(Closure $decision): JsonResponse
    {
        try {
            $request = $decision()->load(['user', 'tenant']);
        } catch (TenantAccessRequestNotDecidableException) {
            return $this->problemDetailsResponse(
                title: __('registration.errors.not_decidable_title'),
                detail: __('registration.errors.not_decidable_detail'),
                status: Response::HTTP_CONFLICT,
            );
        }

        return $this->successResponse(data: new TenantAccessRequestResource($request), message: __('registration.messages.request_decided'));
    }
}
