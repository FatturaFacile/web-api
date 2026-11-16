<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\HumanResources\Http\Requests\AssignScheduleGroupRequest;
use Modules\HumanResources\Http\Requests\ImportTimePunchesRequest;
use Modules\HumanResources\Http\Requests\SaveScheduleGroupRequest;
use Modules\HumanResources\Http\Requests\SaveScheduleRuleRequest;
use Modules\HumanResources\Http\Requests\SaveTimeBadgeRequest;
use Modules\HumanResources\Http\Requests\StoreMealBookingRequest;
use Modules\HumanResources\Http\Requests\TimeReportRequest;
use Modules\HumanResources\Http\Resources\HrRecordResource;
use Modules\HumanResources\Models\TimeBadge;
use Modules\HumanResources\Models\TimeClockEvent;
use Modules\HumanResources\Models\TimeMealBooking;
use Modules\HumanResources\Models\TimeScheduleGroup;
use Modules\HumanResources\Models\TimeScheduleGroupMember;
use Modules\HumanResources\Models\TimeScheduleGroupRule;
use Modules\HumanResources\Services\TimeTracking\PayrollTimeViewService;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeRules;
use Modules\HumanResources\Services\TimeTracking\TimePresenceService;
use Modules\HumanResources\Services\TimeTracking\TimePunchImportService;
use Modules\HumanResources\Services\TimeTracking\TimeRecordSource;
use OpenApi\Attributes as OA;

/**
 * Schedule groups, the derived payroll view, presence, reader import and meal bookings. `employee_id` is a user id, as in
 * the rest of /hr/time.
 */
final class TimeClockDetailsController extends Controller
{
    public function __construct(
        private readonly PayrollTimeViewService $payrollView,
        private readonly TimePresenceService $presence,
        private readonly TimePunchImportService $punches,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * List schedule groups.
     */
    #[OA\Get(path: '/api/v1/hr/schedule-groups', operationId: 'listScheduleGroups', description: 'Return the schedule groups with the history of their rules.', summary: 'List schedule groups', security: [['bearerAuth' => []]], tags: ['Human Resources'], responses: [
        new OA\Response(response: 200, description: 'Groups', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
    ])]
    public function groups(): AnonymousResourceCollection
    {
        return HrRecordResource::collection(TimeScheduleGroup::query()->with('rules')->orderBy('name')->gridPaginate(RequestedPerPage::resolve(200)));
    }

    /**
     * Create a schedule group.
     */
    #[OA\Post(path: '/api/v1/hr/schedule-groups', operationId: 'storeScheduleGroup', description: 'Create a schedule group.', summary: 'Create schedule group', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveScheduleGroupRequest')), tags: ['Human Resources'], responses: [
        new OA\Response(response: 201, description: 'Group', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function storeGroup(SaveScheduleGroupRequest $request): JsonResponse
    {
        return (new HrRecordResource(TimeScheduleGroup::create($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Add a version of the rules.
     */
    #[OA\Post(path: '/api/v1/hr/schedule-groups/{id}/rules', operationId: 'storeScheduleRule', description: 'Add a version of tolerance, rounding and allowance rules, in force from valid_from until the next version. Versions are never changed, and rounding never touches the recorded events: it exists only in the payroll view.', summary: 'Add schedule rule version', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveScheduleRuleRequest')), tags: ['Human Resources'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 201, description: 'Rule version', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function storeRule(SaveScheduleRuleRequest $request, string $id): JsonResponse
    {
        $group = TimeScheduleGroup::query()->findOrFail($id);
        $rule = TimeScheduleGroupRule::create([...$request->validated(), 'schedule_group_id' => $group->id, 'created_by' => $request->user()?->id]);

        return (new HrRecordResource($rule))->response()->setStatusCode(201);
    }

    /**
     * Put a user in a group.
     */
    #[OA\Post(path: '/api/v1/hr/schedule-groups/{id}/members', operationId: 'storeScheduleGroupMember', description: 'Put a user in a schedule group for a period.', summary: 'Add schedule group member', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesAssignScheduleGroupRequest')), tags: ['Human Resources'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 201, description: 'Membership', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function storeMember(AssignScheduleGroupRequest $request, string $id): JsonResponse
    {
        $group = TimeScheduleGroup::query()->findOrFail($id);
        $user = $this->member((string) $request->validated('employee_id'));
        $member = TimeScheduleGroupMember::create([
            'schedule_group_id' => $group->id,
            'user_id' => $user->id,
            'valid_from' => $request->validated('valid_from'),
            'valid_to' => $request->validated('valid_to'),
        ]);

        return (new HrRecordResource($member))->response()->setStatusCode(201);
    }

    /**
     * Register a badge.
     */
    #[OA\Post(path: '/api/v1/hr/time-badges', operationId: 'storeTimeBadge', description: 'Link the code a reader sends to a user.', summary: 'Create time badge', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveTimeBadgeRequest')), tags: ['Human Resources'], responses: [
        new OA\Response(response: 201, description: 'Badge', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function storeBadge(SaveTimeBadgeRequest $request): JsonResponse
    {
        $user = $this->member((string) $request->validated('employee_id'));
        $badge = TimeBadge::create(['user_id' => $user->id, 'badge_code' => $request->validated('badge_code'), 'is_active' => $request->validated('is_active', true)]);

        return (new HrRecordResource($badge))->response()->setStatusCode(201);
    }

    /**
     * The payroll view of the working time.
     */
    #[OA\Get(path: '/api/v1/hr/time/payroll-view', operationId: 'timePayrollView', description: 'Return day by day the recorded real minutes and, derived from them, the minutes after tolerance and rounding and the night, Sunday, holiday and daily allowances of the schedule group in force. Computed on demand and never stored: the clock events stay as recorded.', summary: 'Payroll view of working time', security: [['bearerAuth' => []]], tags: ['Human Resources'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/HumanResourcesFrom'),
        new OA\Parameter(ref: '#/components/parameters/HumanResourcesTo'),
        new OA\Parameter(name: 'employee_id', description: 'User id, the authenticated user when missing', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Payroll view', content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesPayrollTimeView')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function payrollView(TimeReportRequest $request): JsonResponse
    {
        $employeeId = (string) ($request->validated('employee_id') ?? $this->actor($request)->id);
        $this->authorize('viewRecords', [TimeClockEvent::class, $employeeId]);

        return response()->json(['data' => $this->payrollView->view($this->member($employeeId), (string) $request->validated('from'), (string) $request->validated('to'))]);
    }

    /**
     * Who is in now.
     */
    #[OA\Get(path: '/api/v1/hr/time/presence', operationId: 'timePresence', description: 'Return the people working or on a break now, from the last clock event of the last two days. For owners and administrators.', summary: 'Presence', security: [['bearerAuth' => []]], tags: ['Human Resources'], responses: [
        new OA\Response(response: 200, description: 'People present', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
    ])]
    public function presence(): JsonResponse
    {
        $this->authorize('manage', TimeClockEvent::class);

        return response()->json(['data' => $this->presence->current()]);
    }

    /**
     * Import punches from a reader.
     */
    #[OA\Post(path: '/api/v1/hr/time/punches/import', operationId: 'importTimePunches', description: 'Take punches from any reader in a neutral form. reader_id with external_id makes the import repeatable. Punches keep their real time and go to the history; a punch of type Meal becomes a meal booking.', summary: 'Import punches', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesImportTimePunchesRequest')), tags: ['Human Resources'], responses: [
        new OA\Response(response: 200, description: 'Import outcome', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function importPunches(ImportTimePunchesRequest $request): JsonResponse
    {
        $this->authorize('manage', TimeClockEvent::class);

        return response()->json(['data' => $this->punches->import(
            $this->actor($request),
            (string) $request->validated('reader_id'),
            TimeRecordSource::from((string) $request->validated('source', TimeRecordSource::Terminal->value)),
            $request->validated('punches'),
        )]);
    }

    /**
     * List meal bookings.
     */
    #[OA\Get(path: '/api/v1/hr/time/meal-bookings', operationId: 'listMealBookings', description: 'Return the meal bookings of a user in a period. Everyone sees the own, owners and administrators those of the others.', summary: 'List meal bookings', security: [['bearerAuth' => []]], tags: ['Human Resources'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/HumanResourcesFrom'),
        new OA\Parameter(ref: '#/components/parameters/HumanResourcesTo'),
        new OA\Parameter(name: 'employee_id', description: 'User id, the authenticated user when missing', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Meal bookings', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function mealBookings(TimeReportRequest $request): AnonymousResourceCollection
    {
        $employeeId = (string) ($request->validated('employee_id') ?? $this->actor($request)->id);
        $this->authorize('viewRecords', [TimeClockEvent::class, $employeeId]);

        return HrRecordResource::collection(TimeMealBooking::query()
            ->where('user_id', $employeeId)
            ->whereDate('meal_date', '>=', (string) $request->validated('from'))
            ->whereDate('meal_date', '<=', (string) $request->validated('to'))
            ->orderBy('meal_date')
            ->gridPaginate(RequestedPerPage::resolve(25)));
    }

    /**
     * Book a meal.
     */
    #[OA\Post(path: '/api/v1/hr/time/meal-bookings', operationId: 'storeMealBooking', description: 'Book a meal for now, once per user, day and type. A booking for someone else or in the past is for owners and administrators. A meal is not a time event and changes no working period.', summary: 'Book meal', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesStoreMealBookingRequest')), tags: ['Human Resources'], responses: [
        new OA\Response(response: 201, description: 'Meal booking', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function storeMealBooking(StoreMealBookingRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $employeeId = (string) ($request->validated('employee_id') ?? $actor->id);
        $occurredAt = $request->validated('occurred_at');

        if ($employeeId !== (string) $actor->id || $occurredAt !== null) {
            $this->authorize('manage', TimeClockEvent::class);
        }

        $booking = $this->punches->bookMeal(
            $actor,
            (string) $this->member($employeeId)->id,
            $occurredAt === null ? CarbonImmutable::now(SwissWorkingTimeRules::TIMEZONE)->startOfMinute() : SwissWorkingTimeRules::parseLocal((string) $occurredAt),
            (string) $request->validated('meal_type'),
            TimeRecordSource::from((string) $request->validated('source', TimeRecordSource::Web->value)),
            $request->validated('note'),
        );

        return (new HrRecordResource($booking))->response()->setStatusCode(201);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * A user who is a member of the active company.
     */
    private function member(string $employeeId): User
    {
        $user = User::query()->find($employeeId);

        abort_unless($user !== null && $user->belongsToTenant((string) $this->tenantContext->getTenantId()), 404);

        return $user;
    }
}
