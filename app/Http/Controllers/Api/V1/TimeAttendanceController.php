<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceReportRequest;
use App\Http\Requests\ClockInRequest;
use App\Http\Requests\ClockOutRequest;
use App\Http\Resources\TimeEntryResource;
use App\Http\Responses\ApiResponse;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Attendance\AttendanceComplianceService;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HumanResources\Services\TimeTracking\LegacyTimeAttendanceBridge;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deprecated legacy time & attendance endpoints (successor: /api/v1/hr/time). Clock-in and clock-out are mirrored as
 * Human Resources clock events by LegacyTimeAttendanceBridge, so both APIs read the same working time.
 */
class TimeAttendanceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AttendanceComplianceService $complianceService,
        private readonly LegacyTimeAttendanceBridge $legacyBridge,
    ) {}

    /**
     * List paginated time entries for the authenticated user or tenant.
     *
     * @deprecated Use GET /api/v1/hr/time/events.
     */
    #[OA\Get(
        path: '/api/v1/time-attendance/entries',
        operationId: 'getTimeAttendanceEntries',
        description: 'List paginated time entries for the authenticated user or tenant.',
        summary: 'List time attendance entries',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Time & Attendance'],
        deprecated: true,
        parameters: [
            new OA\Parameter(name: 'all_users', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'from_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Time entries retrieved successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = TimeEntry::with(['user', 'approver'])
            ->latest('clock_in');

        // Non-admin users can only view their own time entries
        if (! $request->filled('all_users') || ! $user->can('view-all-attendance')) {
            $query->where('user_id', $user->id);
        }

        if ($request->filled('from_date')) {
            $query->where('clock_in', '>=', Carbon::parse($request->query('from_date'))->startOfDay());
        }

        if ($request->filled('to_date')) {
            $query->where('clock_in', '<=', Carbon::parse($request->query('to_date'))->endOfDay());
        }

        $entries = $query->paginate(25);

        return $this->successResponse(
            data: TimeEntryResource::collection($entries),
            message: 'Time entries retrieved successfully',
            meta: [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'total' => $entries->total(),
            ]
        );
    }

    /**
     * Clock in: register a new work shift start with optional GPS geofencing coords.
     *
     * @deprecated Use POST /api/v1/hr/time/events.
     */
    #[OA\Post(
        path: '/api/v1/time-attendance/clock-in',
        operationId: 'clockIn',
        description: 'Register shift start with optional GPS geofencing coordinates.',
        summary: 'Record clock-in',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Time & Attendance'],
        deprecated: true,
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'clock_in', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'latitude', type: 'number', format: 'float', example: 45.4642),
                    new OA\Property(property: 'longitude', type: 'number', format: 'float', example: 9.1900),
                    new OA\Property(property: 'is_simplified_record', type: 'boolean', default: false),
                    new OA\Property(property: 'notes', type: 'string', example: 'Morning shift'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Clock-in recorded successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Already clocked in', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function clockIn(ClockInRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Check if there is an active clock-in without clock-out
        $activeEntry = TimeEntry::where('user_id', $user->id)
            ->whereNull('clock_out')
            ->first();

        if ($activeEntry !== null) {
            return $this->problemDetailsResponse(
                title: 'Already Clocked In',
                detail: sprintf('You have an open shift started at %s. Please clock out before starting a new one.', $activeEntry->clock_in->toDateTimeString()),
                status: Response::HTTP_CONFLICT
            );
        }

        $entry = $this->legacyBridge->clockIn($user, $request->validated());

        if ($entry === null) {
            return $this->problemDetailsResponse(
                title: 'Already Clocked In',
                detail: 'You have an open shift. Please clock out before starting a new one.',
                status: Response::HTTP_CONFLICT
            );
        }

        return $this->successResponse(
            data: new TimeEntryResource($entry->load('user')),
            message: 'Clock-in recorded successfully',
            statusCode: Response::HTTP_CREATED
        );
    }

    /**
     * Clock out: close the currently active shift and log breaks.
     *
     * @deprecated Use POST /api/v1/hr/time/events.
     */
    #[OA\Post(
        path: '/api/v1/time-attendance/clock-out',
        operationId: 'clockOut',
        description: 'Close active shift and log breaks worked.',
        summary: 'Record clock-out',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Time & Attendance'],
        deprecated: true,
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'clock_out', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'break_minutes', type: 'integer', default: 0),
                    new OA\Property(property: 'notes', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Clock-out recorded successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'No active shift or invalid timestamp', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function clockOut(ClockOutRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $activeEntry = TimeEntry::where('user_id', $user->id)
            ->whereNull('clock_out')
            ->latest('clock_in')
            ->first();

        if ($activeEntry === null) {
            return $this->problemDetailsResponse(
                title: 'No Active Shift Found',
                detail: 'No running shift found to clock out from. Please clock in first.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $validated = $request->validated();
        $clockOutTime = ! empty($validated['clock_out']) ? Carbon::parse($validated['clock_out']) : now();

        if ($clockOutTime->isBefore($activeEntry->clock_in)) {
            return $this->problemDetailsResponse(
                title: 'Invalid Clock-Out Timestamp',
                detail: 'Clock-out time cannot be earlier than clock-in time.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $activeEntry = $this->legacyBridge->clockOut($user, $validated);

        if ($activeEntry === null) {
            return $this->problemDetailsResponse(
                title: 'No Active Shift Found',
                detail: 'No running shift found to clock out from. Please clock in first.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $this->successResponse(
            data: new TimeEntryResource($activeEntry->load('user')),
            message: 'Clock-out recorded successfully'
        );
    }

    /**
     * Monthly Report: returns summarized hours, breaks, and compliance audit for a user.
     *
     * @deprecated Use the HR time reports under /api/v1/hr/time.
     */
    #[OA\Get(
        path: '/api/v1/time-attendance/monthly-report',
        operationId: 'getMonthlyAttendanceReport',
        description: 'Returns summarized hours, breaks, and statutory compliance audit for a user.',
        summary: 'Monthly attendance report',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Time & Attendance'],
        deprecated: true,
        parameters: [
            new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'year', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'month', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'jurisdiction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['IT', 'CH'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Monthly report generated successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function monthlyReport(AttendanceReportRequest $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();
        $targetUserId = $request->input('user_id', $currentUser->id);

        $targetUser = User::query()->find($targetUserId);

        abort_unless($targetUser instanceof User && $targetUser->belongsToTenant((string) $this->tenantContext->getTenantId()), Response::HTTP_NOT_FOUND);

        $year = (int) $request->input('year', now()->year);
        $month = (int) $request->input('month', now()->month);

        $startOfMonth = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        $entries = TimeEntry::where('user_id', $targetUser->id)
            ->whereBetween('clock_in', [$startOfMonth, $endOfMonth])
            ->orderBy('clock_in', 'asc')
            ->get();

        $totalMinutesGross = 0;
        $totalBreakMinutes = 0;

        foreach ($entries as $entry) {
            if ($entry->clock_out) {
                $totalMinutesGross += $entry->clock_out->diffInMinutes($entry->clock_in);
                $totalBreakMinutes += (int) $entry->break_minutes;
            }
        }

        $netMinutesWorked = max(0, $totalMinutesGross - $totalBreakMinutes);
        $netHoursWorked = round($netMinutesWorked / 60.0, 2);

        // Determine jurisdiction: request param or tenant country
        $jurisdiction = $request->input('jurisdiction')
            ?? $this->tenantContext->getTenant()?->country
            ?? 'IT';

        $compliance = ($jurisdiction === 'CH')
            ? $this->complianceService->checkSwissArGCompliance($targetUser, $startOfMonth, $endOfMonth)
            : $this->complianceService->checkItalianCompliance($targetUser, $startOfMonth, $endOfMonth);

        return $this->successResponse(
            data: [
                'user' => [
                    'id' => $targetUser->id,
                    'name' => $targetUser->name,
                    'email' => $targetUser->email,
                ],
                'period' => [
                    'year' => $year,
                    'month' => $month,
                    'start_date' => $startOfMonth->toDateString(),
                    'end_date' => $endOfMonth->toDateString(),
                ],
                'summary' => [
                    'total_shifts' => $entries->count(),
                    'gross_hours' => round($totalMinutesGross / 60.0, 2),
                    'break_hours' => round($totalBreakMinutes / 60.0, 2),
                    'net_hours_worked' => $netHoursWorked,
                ],
                'compliance' => $compliance,
                'entries' => TimeEntryResource::collection($entries),
            ],
            message: 'Monthly attendance report generated successfully'
        );
    }

    /**
     * On-demand labour compliance audit check.
     *
     * @deprecated Use the HR time compliance endpoints under /api/v1/hr/time.
     */
    #[OA\Get(
        path: '/api/v1/time-attendance/compliance-check',
        operationId: 'getAttendanceComplianceCheck',
        description: 'Verify employee statutory working time limits under Italian or Swiss labour law.',
        summary: 'Labour compliance check',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Time & Attendance'],
        deprecated: true,
        parameters: [
            new OA\Parameter(name: 'start_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'end_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'jurisdiction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['IT', 'CH'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Compliance verification completed', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function complianceCheck(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $startDate = $request->filled('start_date')
            ? Carbon::parse($request->query('start_date'))->startOfDay()
            : now()->startOfWeek();

        $endDate = $request->filled('end_date')
            ? Carbon::parse($request->query('end_date'))->endOfDay()
            : now()->endOfWeek();

        $jurisdiction = strtoupper((string) ($request->query('jurisdiction') ?? $this->tenantContext->getTenant()?->country ?? 'IT'));

        $audit = ($jurisdiction === 'CH')
            ? $this->complianceService->checkSwissArGCompliance($user, $startDate, $endDate)
            : $this->complianceService->checkItalianCompliance($user, $startDate, $endDate);

        return $this->successResponse(
            data: [
                'jurisdiction' => $jurisdiction,
                'date_range' => [
                    'start' => $startDate->toDateString(),
                    'end' => $endDate->toDateString(),
                ],
                'audit' => $audit,
            ],
            message: 'Compliance verification completed'
        );
    }
}
