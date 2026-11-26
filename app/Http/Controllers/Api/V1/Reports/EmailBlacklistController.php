<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\StoreEmailBlacklistRequest;
use App\Http\Resources\Reports\EmailBlacklistResource;
use App\Http\Responses\ApiResponse;
use App\Models\EmailBlacklist;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class EmailBlacklistController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(
        path: '/api/v1/email-blacklist',
        operationId: 'listEmailBlacklist',
        description: 'List email blacklist entries for the active tenant (including global bounce/complaint entries).',
        summary: 'List email blacklist entries',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Scheduled ERP Reports'],
        responses: [
            new OA\Response(response: 200, description: 'Blacklist retrieved'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? '');
        $entries = EmailBlacklist::query()
            ->forTenant($tenantId)
            ->latest()
            ->paginate(50);

        return $this->successResponse(
            data: EmailBlacklistResource::collection($entries->items())->resolve($request),
            message: 'Email blacklist retrieved',
            meta: [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ]
        );
    }

    #[OA\Post(
        path: '/api/v1/email-blacklist',
        operationId: 'storeEmailBlacklist',
        description: 'Add an email address to the tenant email blacklist.',
        summary: 'Add email to blacklist',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Scheduled ERP Reports'],
        responses: [
            new OA\Response(response: 201, description: 'Email blacklisted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(StoreEmailBlacklistRequest $request): JsonResponse
    {
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? '');
        $validated = $request->validated();

        $entry = EmailBlacklist::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'email' => strtolower(trim((string) $validated['email'])),
            ],
            [
                'reason' => $validated['reason'],
                'notes' => $validated['notes'] ?? null,
            ]
        );

        return $this->successResponse(
            data: (new EmailBlacklistResource($entry))->resolve($request),
            message: 'Email added to blacklist',
            statusCode: Response::HTTP_CREATED
        );
    }

    #[OA\Delete(
        path: '/api/v1/email-blacklist/{id}',
        operationId: 'deleteEmailBlacklist',
        description: 'Remove an email address from the tenant email blacklist.',
        summary: 'Remove email from blacklist',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Scheduled ERP Reports'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Removed'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? '');
        $entry = EmailBlacklist::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($id)
            ->firstOrFail();

        $entry->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
