<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Modules\HumanResources\Http\Requests\HrAnalyticsRequest;
use Modules\HumanResources\Services\Analytics\HrAnalyticsService;
use OpenApi\Attributes as OA;

/**
 * Workforce analytics: headcount, turnover, tenure, absenteeism, open positions and what expires soon.
 */
final class HrAnalyticsController extends Controller
{
    public function __construct(private readonly HrAnalyticsService $analytics) {}

    /**
     * Headcount analytics.
     */
    #[OA\Get(
        path: '/api/v1/hr/analytics/headcount',
        operationId: 'getHrHeadcount',
        description: 'Headcount on a day (default today) by department, contract type and employment type, and month by month over the range (default the last 12 months).',
        summary: 'Headcount analytics',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [
            new OA\Response(response: 200, description: 'Headcount', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ],
    )]
    public function headcount(HrAnalyticsRequest $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $this->analytics->headcount($from, $to, $this->day($request->validated('as_of')))]);
    }

    /**
     * Turnover analytics.
     */
    #[OA\Get(
        path: '/api/v1/hr/analytics/turnover',
        operationId: 'getHrTurnover',
        description: 'Hires, terminations and turnover rate per month, quarter or year, with the reasons of the exit interviews.',
        summary: 'Turnover analytics',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'granularity', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['month', 'quarter', 'year']))],
        responses: [
            new OA\Response(response: 200, description: 'Turnover', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ],
    )]
    public function turnover(HrAnalyticsRequest $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $this->analytics->turnover($from, $to, (string) ($request->validated('granularity') ?? 'month'))]);
    }

    /**
     * Tenure analytics.
     */
    #[OA\Get(
        path: '/api/v1/hr/analytics/tenure',
        operationId: 'getHrTenure',
        description: 'Average tenure in years of the employees and of the leavers, with the distribution and the average by department.',
        summary: 'Tenure analytics',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [
            new OA\Response(response: 200, description: 'Tenure', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ],
    )]
    public function tenure(HrAnalyticsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->analytics->tenure($this->day($request->validated('as_of')))]);
    }

    /**
     * Absenteeism analytics.
     */
    #[OA\Get(
        path: '/api/v1/hr/analytics/absenteeism',
        operationId: 'getHrAbsenteeism',
        description: 'Absence days of the approved leave over the working days of the employees in the range, by leave category and department; vacation, ROL and study leave are kept out of the rate.',
        summary: 'Absenteeism analytics',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [
            new OA\Response(response: 200, description: 'Absenteeism', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ],
    )]
    public function absenteeism(HrAnalyticsRequest $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $this->analytics->absenteeism($from, $to)]);
    }

    /**
     * Open positions analytics.
     */
    #[OA\Get(
        path: '/api/v1/hr/analytics/positions',
        operationId: 'getHrPositions',
        description: 'Budgeted headcount of the active positions against the employees filling them, and the open job postings.',
        summary: 'Open positions analytics',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [
            new OA\Response(response: 200, description: 'Positions', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ],
    )]
    public function positions(HrAnalyticsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->analytics->positions($this->day($request->validated('as_of')))]);
    }

    /**
     * Upcoming probation ends and contract expiries.
     */
    #[OA\Get(
        path: '/api/v1/hr/analytics/upcoming-expiries',
        operationId: 'getHrUpcomingExpiries',
        description: 'Probation periods and fixed-term contracts ending within the next days (default 90).',
        summary: 'Upcoming probation ends and contract expiries',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(name: 'days', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Expiries', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ],
    )]
    public function upcomingExpiries(HrAnalyticsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->analytics->upcomingExpiries($this->day(null), (int) ($request->validated('days') ?? 90))]);
    }

    /**
     * The requested range, by default the twelve months up to today.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(HrAnalyticsRequest $request): array
    {
        $to = $this->day($request->validated('to'));
        $from = $request->validated('from') !== null ? $this->day($request->validated('from')) : $to->copy()->subMonths(11)->startOfMonth();

        return [$from, $to];
    }

    private function day(?string $date): Carbon
    {
        return $date !== null ? Carbon::parse($date)->startOfDay() : Carbon::today();
    }
}
