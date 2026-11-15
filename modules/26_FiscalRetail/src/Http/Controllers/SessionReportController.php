<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PosSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Modules\FiscalRetail\Http\Resources\RetailSessionReportResource;
use Modules\FiscalRetail\Models\RetailSessionReport;
use Modules\FiscalRetail\Services\SessionReportService;
use OpenApi\Attributes as OA;

/**
 * X and Z reports of the POS cash sessions.
 */
final class SessionReportController extends Controller
{
    #[OA\Get(path: '/api/v1/fiscal-retail/pos/sessions/{id}/x-report', operationId: 'getRetailXReport', description: 'Reading of an open session: sales, discounts, returns, VAT by rate, payments by method and the cash drawer with its expected cash. It changes nothing and can be produced any number of times; each reading is kept.', summary: 'X report of a session', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fiscal Retail'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'X report', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RetailSessionTotals')])),
        new OA\Response(response: 404, description: 'Session not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'The session is closed: read its Z report', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function x(Request $request, string $id, SessionReportService $reports): JsonResponse
    {
        $session = PosSession::query()->findOrFail($id);
        $this->authorize('viewAny', RetailSessionReport::class);

        if ($session->status !== 'open') {
            throw ValidationException::withMessages(['pos_session_id' => __('pos.x_report_open_only')]);
        }

        return response()->json(['data' => $reports->x($session, $request->user() !== null ? (string) $request->user()->getKey() : null)]);
    }

    #[OA\Get(path: '/api/v1/fiscal-retail/pos/sessions/{id}/z-report', operationId: 'getRetailZReport', description: 'The Z report of a closed session, numbered per company and year. It is issued when the session is closed and never changes. It carries the reconciliation with the commercial documents of the session and the daily corrispettivi of its business day.', summary: 'Z report of a session', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fiscal Retail'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Z report', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RetailSessionReport')])),
        new OA\Response(response: 404, description: 'Session not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'The session is still open: read its X report', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function z(Request $request, string $id, SessionReportService $reports): RetailSessionReportResource
    {
        $session = PosSession::query()->findOrFail($id);
        $this->authorize('viewAny', RetailSessionReport::class);

        if ($session->status !== 'closed') {
            throw ValidationException::withMessages(['pos_session_id' => __('pos.z_report_closed_only')]);
        }

        return new RetailSessionReportResource($reports->z($session, $request->user() !== null ? (string) $request->user()->getKey() : null));
    }

    #[OA\Get(path: '/api/v1/fiscal-retail/pos/z-reports', operationId: 'listRetailZReports', description: 'Paginated Z reports, newest first. Filters: from, to (business date).', summary: 'List Z reports', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fiscal Retail'], parameters: [
        new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Z reports', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/RetailSessionReport'))])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', RetailSessionReport::class);
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return RetailSessionReportResource::collection(
            RetailSessionReport::query()
                ->where('kind', 'z')
                ->when($request->filled('from'), static fn ($query) => $query->whereDate('business_date', '>=', (string) $request->query('from')))
                ->when($request->filled('to'), static fn ($query) => $query->whereDate('business_date', '<=', (string) $request->query('to')))
                ->orderByDesc('business_date')
                ->orderByDesc('number')
                ->gridPaginate(max(1, min(100, $request->integer('per_page', 25))))
        );
    }
}
