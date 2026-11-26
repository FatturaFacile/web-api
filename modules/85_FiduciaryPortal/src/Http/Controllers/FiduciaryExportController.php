<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\FiduciaryPortal\Http\Requests\RunStudioExportRequest;
use Modules\FiduciaryPortal\Http\Resources\FiduciaryExportRunResource;
use Modules\FiduciaryPortal\Models\FiduciaryExportRun;
use Modules\FiduciaryPortal\Policies\FiduciaryPortalPolicy;
use Modules\FiduciaryPortal\Services\FiduciaryAuditService;
use Modules\FiduciaryPortal\Services\StudioExportEngine;
use OpenApi\Attributes as OA;

final class FiduciaryExportController extends Controller
{
    use ApiResponse;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly StudioExportEngine $exportEngine,
        private readonly FiduciaryAuditService $audit,
    ) {}

    #[OA\Get(
        path: '/api/v1/fiduciary-portal/exports',
        operationId: 'fiduciaryPortalExportsIndex',
        summary: 'List studio accounting export runs',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 200, description: 'Export runs history')]
    )]
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_VIEW);
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $request->user()?->tenant_id);

        $runs = FiduciaryExportRun::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return $this->successResponse(FiduciaryExportRunResource::collection($runs));
    }

    #[OA\Post(
        path: '/api/v1/fiduciary-portal/exports/validate',
        operationId: 'fiduciaryPortalExportsValidate',
        summary: 'Run pre-export validation and diagnostics for a studio export format',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 200, description: 'Pre-export diagnostics')]
    )]
    public function validateExport(RunStudioExportRequest $request): JsonResponse
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_EXPORT);
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $request->user()?->tenant_id);

        return $this->successResponse($this->exportEngine->validate($tenantId, $request->validated()));
    }

    #[OA\Post(
        path: '/api/v1/fiduciary-portal/exports',
        operationId: 'fiduciaryPortalExportsGenerate',
        summary: 'Generate and persist a studio accounting export file',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 201, description: 'Export run generated')]
    )]
    public function generate(RunStudioExportRequest $request): JsonResponse
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_EXPORT);
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $request->user()?->tenant_id);

        $run = $this->exportEngine->generate($tenantId, $request->validated(), $request->user());

        return $this->createdResponse(new FiduciaryExportRunResource($run), __('fiduciary_portal.export_generated'));
    }

    #[OA\Get(
        path: '/api/v1/fiduciary-portal/exports/{id}/download',
        operationId: 'fiduciaryPortalExportsDownload',
        summary: 'Download a previously generated studio export file',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 200, description: 'Export file stream')]
    )]
    public function download(Request $request, string $id): Response
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_EXPORT);
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $request->user()?->tenant_id);

        $run = FiduciaryExportRun::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $this->audit->record(
            tenantId: $tenantId,
            action: 'export_downloaded',
            summary: "Downloaded studio export [{$run->file_name}].",
            actor: $request->user(),
            targetType: 'fiduciary_export_run',
            targetId: (string) $run->id,
            targetReference: (string) $run->file_name,
        );

        return response((string) $run->content_payload, 200, [
            'Content-Type' => (string) $run->mime_type,
            'Content-Disposition' => "attachment; filename=\"{$run->file_name}\"",
        ]);
    }
}
