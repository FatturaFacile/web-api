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
use Symfony\Component\HttpFoundation\Response;

class TimeAttendanceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AttendanceComplianceService $complianceService
    ) {}

    /**
     * List paginated time entries for the authenticated user or tenant.
     */
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
     */
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

        $validated = $request->validated();

        $entry = new TimeEntry;
        $entry->tenant_id = $this->tenantContext->getTenantId();
        $entry->user_id = $user->id;
        $entry->clock_in = ! empty($validated['clock_in']) ? Carbon::parse($validated['clock_in']) : now();
        $entry->clock_in_lat = $validated['latitude'] ?? null;
        $entry->clock_in_lng = $validated['longitude'] ?? null;
        $entry->is_simplified_record = (bool) ($validated['is_simplified_record'] ?? false);
        $entry->notes = $validated['notes'] ?? null;
        $entry->save();

        return $this->successResponse(
            data: new TimeEntryResource($entry->load('user')),
            message: 'Clock-in recorded successfully',
            statusCode: Response::HTTP_CREATED
        );
    }

    /**
     * Clock out: close the currently active shift and log breaks.
     */
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

        $activeEntry->clock_out = $clockOutTime;
        $activeEntry->break_minutes = (int) ($validated['break_minutes'] ?? 0);
        if (! empty($validated['notes'])) {
            $activeEntry->notes = ($activeEntry->notes ? $activeEntry->notes."\n" : '').$validated['notes'];
        }
        $activeEntry->save();

        return $this->successResponse(
            data: new TimeEntryResource($activeEntry->load('user')),
            message: 'Clock-out recorded successfully'
        );
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
