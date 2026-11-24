<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Quality\Models\QmsNonconformity;
use Modules\Quality\Services\QualityKpiService;
use OpenApi\Attributes as OA;

/**
 * Indicators of the quality management system.
 */
final class KpiController extends Controller
{
    public function __construct(private readonly QualityKpiService $kpis) {}

    #[OA\Get(path: '/api/v1/quality/kpis', operationId: 'getQmsKpis', description: 'Quality indicators of the company: open non-conformities by age (0-30, 31-60, 61-90, over 90 days), by source and by severity; corrective and preventive actions (open, overdue, completed on time, on-time rate); audits and procedures due.', summary: 'Quality KPIs', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], responses: [
        new OA\Response(response: 200, description: 'Indicators', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsKpis')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
    ])]
    public function show(): JsonResponse
    {
        $this->authorize('viewAny', QmsNonconformity::class);

        return response()->json(['data' => $this->kpis->summary()]);
    }
}
