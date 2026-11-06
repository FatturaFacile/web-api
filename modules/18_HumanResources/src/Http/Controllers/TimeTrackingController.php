<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\HumanResources\Http\Requests\CorrectTimeEventRequest;
use Modules\HumanResources\Http\Requests\DeleteTimeEventRequest;
use Modules\HumanResources\Http\Requests\ListTimeRecordsRequest;
use Modules\HumanResources\Http\Requests\SaveTimeDailyRecordRequest;
use Modules\HumanResources\Http\Requests\SaveTimeProfileRequest;
use Modules\HumanResources\Http\Requests\StoreTimeEventRequest;
use Modules\HumanResources\Http\Requests\TimeHolidaysRequest;
use Modules\HumanResources\Http\Requests\TimeReportRequest;
use Modules\HumanResources\Http\Resources\TimeAuditEntryResource;
use Modules\HumanResources\Http\Resources\TimeClockEventResource;
use Modules\HumanResources\Http\Resources\TimeDailyRecordResource;
use Modules\HumanResources\Http\Resources\TimeRecordingProfileResource;
use Modules\HumanResources\Models\TimeAuditEntry;
use Modules\HumanResources\Models\TimeClockEvent;
use Modules\HumanResources\Models\TimeDailyRecord;
use Modules\HumanResources\Models\TimeRecordingProfile;
use Modules\HumanResources\Services\TimeRecordService;
use Modules\HumanResources\Services\TimeTracking\HolidayEntry;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeRules;
use Modules\HumanResources\Services\TimeTracking\TimeEventType;
use Modules\HumanResources\Services\TimeTracking\TimeRecordSource;
use Modules\HumanResources\Services\WorkingTimeCalculator;

/**
 * Swiss working time recording. The employee is the user: `employee_id` carries a user id, because `user_id` and
 * `tenant_id` are reserved parameters of the request context; without it a request refers to the authenticated user.
 */
final class TimeTrackingController extends Controller
{
    public function __construct(
        private readonly TimeRecordService $records,
        private readonly WorkingTimeCalculator $calculator,
        private readonly TenantContext $tenantContext,
    ) {}

    public function storeEvent(StoreTimeEventRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $employeeId = (string) ($request->validated('employee_id') ?? $actor->id);
        $eventType = TimeEventType::from((string) $request->validated('event_type'));
        $source = $request->validated('source');
        $note = $request->validated('note');
        $occurredAt = $request->validated('occurred_at');

        if ($employeeId === (string) $actor->id && $occurredAt === null) {
            $source = $source === null ? TimeRecordSource::Web : TimeRecordSource::from((string) $source);

            return response()->json([
                'data' => new TimeClockEventResource($this->records->clock($actor, $eventType, $source, $note)),
            ], 201);
        }

        $this->authorize('manage', TimeClockEvent::class);
        $this->employee($employeeId);

        $event = $this->records->record(
            actor: $actor,
            employeeId: $employeeId,
            occurredAt: $occurredAt === null
                ? CarbonImmutable::now(SwissWorkingTimeRules::TIMEZONE)->startOfMinute()
                : SwissWorkingTimeRules::parseLocal((string) $occurredAt),
            eventType: $eventType,
            source: $source === null ? TimeRecordSource::Manual : TimeRecordSource::from((string) $source),
            note: $note,
            reason: (string) $request->validated('reason'),
        );

        return response()->json(['data' => new TimeClockEventResource($event)], 201);
    }

    public function events(ListTimeRecordsRequest $request): AnonymousResourceCollection
    {
        $employeeId = $this->employeeId($request);
        $this->authorize('viewRecords', [TimeClockEvent::class, $employeeId]);

        $events = TimeClockEvent::query()
            ->when($request->boolean('include_deleted'), fn ($query) => $query->withTrashed())
            ->where('user_id', $employeeId)
            ->betweenLocalDays($request->validated('from'), $request->validated('to'))
            ->orderBy('occurred_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate((int) $request->validated('per_page', 50));

        return TimeClockEventResource::collection($events);
    }

    public function correctEvent(CorrectTimeEventRequest $request, string $id): JsonResponse
    {
        $this->authorize('manage', TimeClockEvent::class);

        $event = TimeClockEvent::withTrashed()->findOrFail($id);
        $values = ['source' => TimeRecordSource::from((string) $request->validated('source', TimeRecordSource::Manual->value))];

        if ($request->has('occurred_at')) {
            $values['occurred_at'] = SwissWorkingTimeRules::parseLocal((string) $request->validated('occurred_at'));
        }

        if ($request->has('event_type')) {
            $values['event_type'] = TimeEventType::from((string) $request->validated('event_type'));
        }

        if ($request->has('note')) {
            $values['note'] = $request->validated('note');
        }

        return response()->json([
            'data' => new TimeClockEventResource($this->records->correct($this->actor($request), $event, $values, (string) $request->validated('reason'))),
        ]);
    }

    public function destroyEvent(DeleteTimeEventRequest $request, string $id): JsonResponse
    {
        $this->authorize('manage', TimeClockEvent::class);

        $event = TimeClockEvent::query()->findOrFail($id);
        $this->records->delete($this->actor($request), $event, (string) $request->validated('reason'));

        return response()->json(['data' => new TimeClockEventResource($event)]);
    }

    public function saveDailyRecord(SaveTimeDailyRecordRequest $request): JsonResponse
    {
        $employeeId = $this->employeeId($request);
        $this->authorize('saveDailyRecord', [TimeDailyRecord::class, $employeeId]);
        $this->employee($employeeId);

        $record = $this->records->saveDailyRecord(
            actor: $this->actor($request),
            employeeId: $employeeId,
            workDate: (string) $request->validated('date'),
            values: [
                'worked_minutes' => (int) $request->validated('worked_minutes'),
                'start_time' => $this->localInstant($request->validated('start_time')),
                'end_time' => $this->localInstant($request->validated('end_time')),
                'note' => $request->validated('note'),
            ],
            reason: $request->validated('reason'),
        );

        return response()->json(['data' => new TimeDailyRecordResource($record)]);
    }

    public function report(TimeReportRequest $request): JsonResponse
    {
        $employeeId = $this->employeeId($request);
        $this->authorize('viewRecords', [TimeClockEvent::class, $employeeId]);

        $report = $this->calculator->evaluate(
            $this->employee($employeeId),
            (string) $request->validated('from'),
            (string) $request->validated('to'),
        );

        return response()->json(['data' => $report->toArray()]);
    }

    public function audit(ListTimeRecordsRequest $request): AnonymousResourceCollection
    {
        $employeeId = $request->validated('employee_id');
        $isManager = $request->user()->can('manage', TimeAuditEntry::class);

        if ($employeeId === null && ! $isManager) {
            $employeeId = (string) $this->actor($request)->id;
        }

        $this->authorize('viewAudit', [TimeAuditEntry::class, $employeeId]);

        $from = $request->validated('from');
        $to = $request->validated('to');

        $entries = TimeAuditEntry::query()
            ->when($employeeId, fn ($query) => $query->where('user_id', $employeeId))
            ->when($from, fn ($query) => $query->where('occurred_at', '>=', SwissWorkingTimeRules::localDate((string) $from)->utc()->format('Y-m-d H:i:s')))
            ->when($to, fn ($query) => $query->where('occurred_at', '<', SwissWorkingTimeRules::localDate((string) $to)->addDay()->utc()->format('Y-m-d H:i:s')))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate((int) $request->validated('per_page', 50));

        return TimeAuditEntryResource::collection($entries);
    }

    public function holidays(TimeHolidaysRequest $request): JsonResponse
    {
        $canton = $request->validated('canton')
            ?? TimeRecordingProfile::query()->where('user_id', $this->actor($request)->id)->value('canton');
        $year = (int) ($request->validated('year') ?? CarbonImmutable::now(SwissWorkingTimeRules::TIMEZONE)->year);

        return response()->json([
            'data' => array_map(static fn (HolidayEntry $holiday): array => [
                'date' => $holiday->date,
                'name' => $holiday->name,
                'day_fraction' => (float) $holiday->dayFraction,
            ], $this->calculator->holidays($canton, $year)),
        ]);
    }

    public function profile(string $userId): JsonResponse
    {
        $this->authorize('viewRecords', [TimeRecordingProfile::class, $userId]);
        $this->employee($userId);

        $profile = TimeRecordingProfile::query()->where('user_id', $userId)->first()
            ?? new TimeRecordingProfile(['user_id' => $userId]);

        return response()->json(['data' => new TimeRecordingProfileResource($profile)]);
    }

    public function saveProfile(SaveTimeProfileRequest $request, string $userId): JsonResponse
    {
        $this->authorize('manage', TimeRecordingProfile::class);
        $this->employee($userId);

        $values = collect($request->validated())->except('reason')->all();
        $profile = $this->records->saveProfile($this->actor($request), $userId, $values, $request->validated('reason'));

        return response()->json(['data' => new TimeRecordingProfileResource($profile)]);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * The employee a request refers to: never `user_id` or `tenant_id`.
     */
    private function employeeId(Request $request): string
    {
        return (string) ($request->input('employee_id') ?? $this->actor($request)->id);
    }

    /**
     * The employee, who must be a member of the active tenant.
     */
    private function employee(string $employeeId): User
    {
        $employee = User::query()->find($employeeId);

        abort_unless($employee !== null && $employee->belongsToTenant((string) $this->tenantContext->getTenantId()), 404);

        return $employee;
    }

    private function localInstant(mixed $value): ?CarbonImmutable
    {
        return $value === null ? null : SwissWorkingTimeRules::parseLocal((string) $value);
    }
}
