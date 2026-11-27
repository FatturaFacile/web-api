<?php

declare(strict_types=1);

namespace Modules\Esterometro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Esterometro\Services\SelfInvoiceDeadlineMonitorService;
use OpenApi\Attributes as OA;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly SelfInvoiceDeadlineMonitorService $monitor,
    ) {}

    #[OA\Get(
        path: '/api/v1/esterometro/dashboard',
        summary: 'Esterometro & Reverse Charge compliance dashboard KPIs and TD16-TD28 breakdown',
        tags: ['Esterometro'],
        responses: [new OA\Response(response: 200, description: 'Dashboard summary')],
    )]
    public function index(Request $request): JsonResponse
    {
        $tenantId = (string) $request->user()->tenant_id;

        return response()->json([
            'data' => $this->monitor->getDashboardSummary($tenantId),
        ]);
    }
}
