<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\FiduciaryPortal\Policies\FiduciaryPortalPolicy;
use Modules\FiduciaryPortal\Services\DigitalBalanceSheetDossierBuilder;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class FiduciaryDossierController extends Controller
{
    use ApiResponse;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DigitalBalanceSheetDossierBuilder $dossierBuilder,
    ) {}

    #[OA\Get(
        path: '/api/v1/fiduciary-portal/dossier/preview',
        operationId: 'fiduciaryPortalDossierPreview',
        summary: 'Preview Digital Balance Sheet Dossier dataset counts',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 200, description: 'Dossier dataset preview')]
    )]
    public function preview(Request $request): JsonResponse
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_DOSSIER);
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $request->user()?->tenant_id);

        return $this->successResponse($this->dossierBuilder->preview(
            tenantId: $tenantId,
            fiscalYearId: $request->query('fiscal_year_id') ? (string) $request->query('fiscal_year_id') : null,
            fromDate: $request->query('from_date') ? (string) $request->query('from_date') : null,
            toDate: $request->query('to_date') ? (string) $request->query('to_date') : null,
        ));
    }

    #[OA\Get(
        path: '/api/v1/fiduciary-portal/dossier/download',
        operationId: 'fiduciaryPortalDossierDownload',
        summary: 'Download Digital Balance Sheet Dossier (Fascicolo Digitale di Bilancio) ZIP archive',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 200, description: 'ZIP dossier download')]
    )]
    public function download(Request $request): BinaryFileResponse
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_DOSSIER);
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $request->user()?->tenant_id);

        $built = $this->dossierBuilder->build(
            tenantId: $tenantId,
            fiscalYearId: $request->query('fiscal_year_id') ? (string) $request->query('fiscal_year_id') : null,
            fromDate: $request->query('from_date') ? (string) $request->query('from_date') : null,
            toDate: $request->query('to_date') ? (string) $request->query('to_date') : null,
            studioSystem: (string) $request->query('studio_system', 'universal'),
            actor: $request->user(),
        );

        return response()
            ->download($built['path'], $built['file_name'], ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }
}
