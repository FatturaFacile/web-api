<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\TimeEntryEventType;
use App\Exceptions\AttendanceStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceReportRequest;
use App\Http\Requests\BreakEventRequest;
use App\Http\Requests\ClockInRequest;
use App\Http\Requests\ClockOutRequest;
use App\Http\Resources\TimeEntryResource;
use App\Http\Responses\ApiResponse;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Attendance\AttendanceComplianceService;
use App\Services\Attendance\AttendanceIdempotencyService;
use App\Services\Attendance\AttendanceReportService;
use App\Services\Attendance\AttendanceTrackingService;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TimeAttendanceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AttendanceComplianceService $complianceService,
        private readonly AttendanceReportService $reportService,
        private readonly AttendanceTrackingService $trackingService,
        private readonly AttendanceIdempotencyService $idempotencyService
    ) {}

    /**
     * List paginated time entries for the authenticated user or tenant.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = TimeEntry::with(['user', 'approver', 'events'])
            ->orderByRaw('COALESCE(corrected_clock_in, clock_in) DESC');

        // Non-admin users can only view their own time entries
        if (! $request->filled('all_users') || ! $user->can('view-all-attendance')) {
            $query->where('user_id', $user->id);
        }

        if ($request->filled('from_date')) {
            $query->whereRaw('COALESCE(corrected_clock_in, clock_in) >= ?', [Carbon::parse($request->query('from_date'))->startOfDay()]);
        }

        if ($request->filled('to_date')) {
            $query->whereRaw('COALESCE(corrected_clock_in, clock_in) <= ?', [Carbon::parse($request->query('to_date'))->endOfDay()]);
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
     */
    public function clockIn(ClockInRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->attendanceResponse(
            $request,
            'clock-in',
            fn (): TimeEntry => $this->trackingService->clockIn($user, $request->validated()),
            'Clock-in recorded successfully',
            Response::HTTP_CREATED
        );
    }

    /**
     * Clock out: close the currently active shift and log breaks.
     */
    public function clockOut(ClockOutRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->attendanceResponse(
            $request,
            'clock-out',
            fn (): TimeEntry => $this->trackingService->clockOut($user, $request->validated()),
            'Clock-out recorded successfully'
        );
    }

    public function breakStart(BreakEventRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->attendanceResponse(
            $request,
            'break-start',
            fn (): TimeEntry => $this->trackingService->recordBreak($user, TimeEntryEventType::BreakStarted),
            'Break start recorded successfully',
            Response::HTTP_CREATED
        );
    }

    public function breakEnd(BreakEventRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->attendanceResponse(
            $request,
            'break-end',
            fn (): TimeEntry => $this->trackingService->recordBreak($user, TimeEntryEventType::BreakEnded),
            'Break end recorded successfully',
            Response::HTTP_CREATED
        );
    }

    /** @param Closure(): TimeEntry $operation */
    private function attendanceResponse(FormRequest $request, string $action, Closure $operation, string $message, int $status = Response::HTTP_OK): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            return $this->idempotencyService->execute(
                $user,
                $action,
                $request->validated(),
                fn (): JsonResponse => $this->successResponse(
                    data: new TimeEntryResource($operation()),
                    message: $message,
                    statusCode: $status
                )
            );
        } catch (AttendanceStateException $exception) {
            return $this->problemDetailsResponse(
                title: $exception->title,
                detail: $exception->getMessage(),
                status: $exception->status
            );
        }
    }

    /**
     * Monthly Report: returns summarized hours, breaks, and compliance audit for a user.
     */
    public function monthlyReport(AttendanceReportRequest $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();
        $targetUserId = $request->input('user_id', $currentUser->id);

        /** @var User $targetUser */
        $targetUser = User::findOrFail($targetUserId);

        $year = (int) $request->input('year', now()->year);
        $month = (int) $request->input('month', now()->month);

        $startOfMonth = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        $entries = TimeEntry::with('events')->where('user_id', $targetUser->id)
            ->effectivePeriod($startOfMonth, $startOfMonth->copy()->addMonth())
            ->orderByRaw('COALESCE(corrected_clock_in, clock_in) ASC')
            ->get();

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
                'summary' => $this->reportService->summarize($entries),
                'compliance' => $compliance,
                'entries' => TimeEntryResource::collection($entries),
            ],
            message: 'Monthly attendance report generated successfully'
        );
    }

    public function annualReport(AttendanceReportRequest $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();

        /** @var User $targetUser */
        $targetUser = User::findOrFail($request->input('user_id') ?? $currentUser->id);
        $year = (int) ($request->input('year') ?? now()->year);

        return $this->successResponse(
            data: [
                'user' => [
                    'id' => $targetUser->id,
                    'name' => $targetUser->name,
                    'email' => $targetUser->email,
                ],
                ...$this->reportService->annualReport($targetUser, $year),
            ],
            message: 'Annual attendance report generated successfully'
        );
    }

    /**
     * On-demand labour compliance audit check.
     */
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
