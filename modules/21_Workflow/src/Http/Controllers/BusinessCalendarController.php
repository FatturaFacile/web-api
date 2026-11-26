<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Workflow\Http\Requests\SaveBusinessCalendarRequest;
use Modules\Workflow\Http\Requests\SeedCalendarHolidaysRequest;
use Modules\Workflow\Http\Requests\StoreCalendarHolidayRequest;
use Modules\Workflow\Models\BusinessCalendar;
use Modules\Workflow\Models\BusinessCalendarHoliday;
use Modules\Workflow\Services\HolidayCalendarFactory;
use OpenApi\Attributes as OA;

/**
 * Business calendars the SLA, reminder and escalation clocks run on: working days and hours, plus the public holidays of
 * Switzerland (federal and cantonal) and Italy (national and patron saint). Read by every workflow user, written by owners
 * and administrators.
 */
final class BusinessCalendarController extends Controller
{
    use ApiResponse;

    /**
     * Lists the calendars of the tenant.
     */
    #[OA\Get(
        path: '/api/v1/workflows/calendars',
        operationId: 'listWorkflowsCalendars',
        description: 'Lists the business calendars of the active tenant.',
        summary: 'List business calendars',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Business Calendars'],
        responses: [
            new OA\Response(response: 200, description: 'Calendars', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List business calendars', tags: ['Workflow Engine'])]
    public function index(): JsonResponse
    {
        /** @var list<BusinessCalendar> $calendars */
        $calendars = BusinessCalendar::query()
            ->where('tenant_id', $this->tenantId())
            ->withCount('holidays')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->all();

        return $this->successResponse(array_map(fn (BusinessCalendar $calendar): array => $this->shape($calendar), $calendars), 'Calendars retrieved');
    }

    /**
     * Creates a calendar.
     */
    #[OA\Post(
        path: '/api/v1/workflows/calendars',
        operationId: 'createWorkflowsCalendar',
        description: 'Creates a business calendar.',
        summary: 'Create a business calendar',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Business Calendars'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowSaveBusinessCalendarRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Calendar created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Create a business calendar', tags: ['Workflow Engine'])]
    public function store(SaveBusinessCalendarRequest $request): JsonResponse
    {
        $calendar = DB::transaction(function () use ($request): BusinessCalendar {
            $data = $request->validated();

            if ((bool) ($data['is_default'] ?? false)) {
                BusinessCalendar::query()->where('tenant_id', $this->tenantId())->update(['is_default' => false]);
            }

            return BusinessCalendar::create([...$data, 'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId()]);
        });

        // The columns the request did not send carry their database defaults: read them back.
        return $this->successResponse($this->shape($calendar->refresh()->loadCount('holidays')), 'Calendar created', 201);
    }

    /**
     * Updates a calendar.
     */
    #[OA\Put(
        path: '/api/v1/workflows/calendars/{id}',
        operationId: 'updateWorkflowsCalendar',
        description: 'Changes working days, hours, time zone or the default flag of a business calendar.',
        summary: 'Update a business calendar',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Business Calendars'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowSaveBusinessCalendarRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Calendar updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Update a business calendar', tags: ['Workflow Engine'])]
    public function update(SaveBusinessCalendarRequest $request, string $id): JsonResponse
    {
        $calendar = $this->find($id);

        DB::transaction(function () use ($request, $calendar): void {
            $data = $request->validated();

            if ((bool) ($data['is_default'] ?? false)) {
                BusinessCalendar::query()->where('tenant_id', $this->tenantId())->where('id', '!=', $calendar->id)->update(['is_default' => false]);
            }

            $calendar->update($data);
        });

        return $this->successResponse($this->shape($calendar->loadCount('holidays')), 'Calendar updated');
    }

    /**
     * Lists the holidays of a calendar, optionally of one year.
     */
    #[OA\Get(
        path: '/api/v1/workflows/calendars/{id}/holidays',
        operationId: 'listWorkflowsCalendarHolidays',
        description: 'Lists the holidays of a business calendar, optionally of one year.',
        summary: 'List calendar holidays',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Business Calendars'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'year', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Holidays', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List calendar holidays', tags: ['Workflow Engine'])]
    public function holidays(Request $request, string $id): JsonResponse
    {
        $calendar = $this->find($id);
        $year = $request->query('year');

        /** @var list<BusinessCalendarHoliday> $holidays */
        $holidays = $calendar->holidays()
            ->when(is_numeric($year), static fn ($query) => $query->whereBetween('holiday_date', [sprintf('%04d-01-01', (int) $year), sprintf('%04d-12-31', (int) $year)]))
            ->orderBy('holiday_date')
            ->get()
            ->all();

        return $this->successResponse(array_map(fn (BusinessCalendarHoliday $holiday): array => $this->shapeHoliday($holiday), $holidays), 'Holidays retrieved');
    }

    /**
     * Adds a holiday.
     */
    #[OA\Post(
        path: '/api/v1/workflows/calendars/{id}/holidays',
        operationId: 'createWorkflowsCalendarHoliday',
        description: 'Adds a holiday to a business calendar. A day already listed is updated.',
        summary: 'Add a calendar holiday',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Business Calendars'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowStoreCalendarHolidayRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Holiday saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Add a calendar holiday', tags: ['Workflow Engine'])]
    public function storeHoliday(StoreCalendarHolidayRequest $request, string $id): JsonResponse
    {
        $calendar = $this->find($id);
        $data = $request->validated();

        $holiday = $this->upsertHoliday($calendar, $data['holiday_date'], $data['name'], $data['holiday_type'] ?? 'company', $data['region'] ?? null);

        return $this->successResponse($this->shapeHoliday($holiday), 'Holiday saved', 201);
    }

    /**
     * Removes a holiday.
     */
    #[OA\Delete(
        path: '/api/v1/workflows/calendars/{id}/holidays/{holidayId}',
        operationId: 'deleteWorkflowsCalendarHoliday',
        description: 'Removes a holiday from a business calendar.',
        summary: 'Delete a calendar holiday',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Business Calendars'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'holidayId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Delete a calendar holiday', tags: ['Workflow Engine'])]
    public function destroyHoliday(string $id, string $holidayId): Response
    {
        $this->find($id)->holidays()->whereKey($holidayId)->firstOrFail()->delete();

        return response()->noContent();
    }

    /**
     * Generates the public holidays of a year.
     */
    #[OA\Post(
        path: '/api/v1/workflows/calendars/{id}/seed-holidays',
        operationId: 'seedWorkflowsCalendarHolidays',
        description: 'Writes the public holidays of a year into the calendar: Swiss federal and cantonal days (country CH, optional canton) or Italian national days and an optional patron saint (country IT). Existing days are updated, not duplicated.',
        summary: 'Seed calendar holidays',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Business Calendars'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowSeedCalendarHolidaysRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Holidays written', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Seed calendar holidays', tags: ['Workflow Engine'])]
    public function seedHolidays(SeedCalendarHolidaysRequest $request, HolidayCalendarFactory $factory, string $id): JsonResponse
    {
        $calendar = $this->find($id);
        $holidays = $factory->holidays($request->string('country')->value(), (int) $request->input('year'), $request->input('canton'), $request->input('patron_saint'));

        DB::transaction(function () use ($calendar, $holidays): void {
            foreach ($holidays as $holiday) {
                $this->upsertHoliday($calendar, $holiday['date'], $holiday['name'], $holiday['type'], $holiday['region']);
            }
        });

        return $this->successResponse(['written' => count($holidays)], 'Holidays written');
    }

    private function upsertHoliday(BusinessCalendar $calendar, string $date, string $name, string $type, ?string $region): BusinessCalendarHoliday
    {
        /** @var BusinessCalendarHoliday|null $existing */
        $existing = $calendar->holidays()->whereDate('holiday_date', $date)->first();
        $attributes = ['name' => $name, 'holiday_type' => $type, 'region' => $region];

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing;
        }

        return BusinessCalendarHoliday::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $calendar->tenant_id,
            'business_calendar_id' => $calendar->id,
            'holiday_date' => $date,
            ...$attributes,
        ]);
    }

    private function find(string $id): BusinessCalendar
    {
        /** @var BusinessCalendar $calendar */
        $calendar = BusinessCalendar::query()->where('tenant_id', $this->tenantId())->whereKey($id)->firstOrFail();

        return $calendar;
    }

    private function tenantId(): string
    {
        return (string) app(TenantContext::class)->getTenantId();
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(BusinessCalendar $calendar): array
    {
        return [
            'id' => $calendar->id,
            'name' => $calendar->name,
            'timezone' => $calendar->timezone,
            'work_monday' => (bool) $calendar->work_monday,
            'work_tuesday' => (bool) $calendar->work_tuesday,
            'work_wednesday' => (bool) $calendar->work_wednesday,
            'work_thursday' => (bool) $calendar->work_thursday,
            'work_friday' => (bool) $calendar->work_friday,
            'work_saturday' => (bool) $calendar->work_saturday,
            'work_sunday' => (bool) $calendar->work_sunday,
            'work_hours_start' => substr((string) $calendar->work_hours_start, 0, 5),
            'work_hours_end' => substr((string) $calendar->work_hours_end, 0, 5),
            'is_default' => (bool) $calendar->is_default,
            'holidays_count' => (int) ($calendar->holidays_count ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shapeHoliday(BusinessCalendarHoliday $holiday): array
    {
        return [
            'id' => $holiday->id,
            'holiday_date' => $holiday->holiday_date->toDateString(),
            'name' => $holiday->name,
            'holiday_type' => $holiday->holiday_type ?? 'national',
            'region' => $holiday->region,
        ];
    }
}
