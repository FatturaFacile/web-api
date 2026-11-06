<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Analytics\Services\DueCalendar;
use Modules\Analytics\Services\RevenueAnalytics;
use OpenApi\Attributes as OA;

final class AnalyticsController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/analytics/due-calendar',
        operationId: 'getDueCalendar',
        description: 'Open receivables and payables by due date, in one list. `direction` is `in` for what is to collect and `out` for what is to pay. The period defaults to the current month and cannot exceed 366 days.',
        summary: 'Payment and expense calendar',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['in', 'out'])),
            new OA\Parameter(name: 'contact_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'overdue_only', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The calendar', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid filters', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function dueCalendar(Request $request, DueCalendar $calendar): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'direction' => ['nullable', 'in:in,out'],
            'contact_id' => ['nullable', 'uuid'],
            'overdue_only' => ['nullable', 'boolean'],
        ]);

        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from']) : CarbonImmutable::now()->startOfMonth();
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to']) : $from->endOfMonth();

        if ($from->diffInDays($to) > 366) {
            abort(422, 'The period cannot be longer than 366 days.');
        }

        $entries = $calendar->entries($from, $to, [
            'direction' => $validated['direction'] ?? null,
            'contact_id' => $validated['contact_id'] ?? null,
            'overdue_only' => (bool) ($validated['overdue_only'] ?? false),
        ]);

        $sum = static fn (string $direction): string => $entries->where('direction', $direction)->reduce(static fn (string $total, array $entry): string => bcadd($total, $entry['outstanding'], 2), '0.00');

        return $this->successResponse([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'entries' => $entries->all(),
            'totals' => ['to_collect' => $sum('in'), 'to_pay' => $sum('out'), 'balance' => bcsub($sum('in'), $sum('out'), 2)],
        ], 'Due calendar retrieved');
    }

    #[OA\Get(
        path: '/api/v1/analytics/revenue-comparison',
        operationId: 'getRevenueComparison',
        description: 'Issued revenue net of VAT and credit notes by month, for a year and the year before. It extends the all-time totals of `/dashboard/kpi-summary` with a time axis.',
        summary: 'Year over year revenue',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics'],
        parameters: [new OA\Parameter(name: 'year', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The comparison', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function revenueComparison(Request $request, RevenueAnalytics $analytics): JsonResponse
    {
        $validated = $request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']]);

        return $this->successResponse($analytics->yearOverYear((int) ($validated['year'] ?? now()->format('Y'))), 'Revenue comparison retrieved');
    }

    #[OA\Get(
        path: '/api/v1/analytics/breakdown/{dimension}',
        operationId: 'getAnalyticsBreakdown',
        description: 'A year compared with the one before, by `customer-type`, `product` or `purchases` (by supplier). Amounts are net of VAT.',
        summary: 'Comparison by dimension',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics'],
        parameters: [
            new OA\Parameter(name: 'dimension', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['customer-type', 'product', 'purchases'])),
            new OA\Parameter(name: 'year', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The breakdown', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function breakdown(Request $request, string $dimension, RevenueAnalytics $analytics): JsonResponse
    {
        $validated = $request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']]);

        return $this->successResponse($analytics->breakdown($dimension, (int) ($validated['year'] ?? now()->format('Y'))), 'Breakdown retrieved');
    }
}
