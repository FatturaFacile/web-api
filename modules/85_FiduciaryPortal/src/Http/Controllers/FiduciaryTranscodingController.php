<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\FiduciaryPortal\Http\Requests\BulkAccountTranscodingRequest;
use Modules\FiduciaryPortal\Http\Requests\SaveAccountTranscodingRequest;
use Modules\FiduciaryPortal\Policies\FiduciaryPortalPolicy;
use Modules\FiduciaryPortal\Services\FiduciaryTranscodingService;
use OpenApi\Attributes as OA;

final class FiduciaryTranscodingController extends Controller
{
    use ApiResponse;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly FiduciaryTranscodingService $transcoding,
    ) {}

    #[OA\Get(
        path: '/api/v1/fiduciary-portal/transcodings',
        operationId: 'fiduciaryPortalTranscodingsIndex',
        summary: 'List Chart of Accounts transcoding matrix and unmapped postable accounts',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 200, description: 'Transcoding matrix')]
    )]
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_VIEW);
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $request->user()?->tenant_id);

        return $this->successResponse($this->transcoding->matrix(
            tenantId: $tenantId,
            studioSystem: (string) $request->query('studio_system', 'universal'),
            search: $request->query('search') ? (string) $request->query('search') : null,
            unmappedOnly: $request->boolean('unmapped_only'),
        ));
    }

    #[OA\Post(
        path: '/api/v1/fiduciary-portal/transcodings',
        operationId: 'fiduciaryPortalTranscodingsStore',
        summary: 'Create or update a single account transcoding rule',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 200, description: 'Transcoding rule saved')]
    )]
    public function store(SaveAccountTranscodingRequest $request): JsonResponse
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_TRANSCODE);
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $request->user()?->tenant_id);

        $rule = $this->transcoding->saveRule($tenantId, $request->validated(), $request->user());

        return $this->successResponse($rule, __('fiduciary_portal.transcoding_saved'));
    }

    #[OA\Post(
        path: '/api/v1/fiduciary-portal/transcodings/bulk',
        operationId: 'fiduciaryPortalTranscodingsBulk',
        summary: 'Bulk upsert account transcoding rules for a studio system',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 200, description: 'Bulk transcoding rules saved')]
    )]
    public function bulkUpsert(BulkAccountTranscodingRequest $request): JsonResponse
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_TRANSCODE);
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $request->user()?->tenant_id);
        $validated = $request->validated();

        $result = $this->transcoding->bulkUpsert(
            tenantId: $tenantId,
            studioSystem: (string) $validated['studio_system'],
            items: (array) $validated['items'],
            actor: $request->user(),
        );

        return $this->successResponse(['saved' => $result['saved']], __('fiduciary_portal.transcoding_bulk_saved'));
    }

    #[OA\Post(
        path: '/api/v1/fiduciary-portal/transcodings/seed-defaults',
        operationId: 'fiduciaryPortalTranscodingsSeedDefaults',
        summary: 'Auto-seed default studio account transcoding mappings',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 200, description: 'Default transcodings seeded')]
    )]
    public function seedDefaults(Request $request): JsonResponse
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_TRANSCODE);
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $request->user()?->tenant_id);
        $studioSystem = (string) $request->input('studio_system', 'universal');

        $created = $this->transcoding->seedDefaults($tenantId, $studioSystem, $request->user());

        return $this->successResponse(['created' => $created], __('fiduciary_portal.transcoding_seeded'));
    }

    #[OA\Delete(
        path: '/api/v1/fiduciary-portal/transcodings/{id}',
        operationId: 'fiduciaryPortalTranscodingsDestroy',
        summary: 'Delete an account transcoding rule',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 200, description: 'Transcoding rule deleted')]
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_TRANSCODE);
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $request->user()?->tenant_id);

        $this->transcoding->deleteRule($tenantId, $id, $request->user());

        return $this->successResponse(null, __('fiduciary_portal.transcoding_deleted'));
    }
}
