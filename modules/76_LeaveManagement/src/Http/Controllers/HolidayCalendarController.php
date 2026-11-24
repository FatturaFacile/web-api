<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\LeaveManagement\Http\Controllers\Concerns\ResolvesLeaveContext;
use Modules\LeaveManagement\Http\Requests\SaveHolidayCalendarRequest;
use Modules\LeaveManagement\Http\Resources\HolidayCalendarResource;
use Modules\LeaveManagement\Models\HolidayCalendar;
use Modules\LeaveManagement\Services\HolidayCatalogService;
use OpenApi\Attributes as OA;

/**
 * Holiday calendar management and official CH (26 cantons) / IT (national + patron saint) catalog.
 */
final class HolidayCalendarController extends Controller
{
    use ApiResponse;
    use ResolvesLeaveContext;

    public function __construct(
        private readonly HolidayCatalogService $catalogService,
    ) {}

    #[OA\Get(
        path: '/api/v1/leave-management/holidays/catalog',
        operationId: 'leaveMgmtHolidayCatalog',
        summary: 'Generate official statutory holidays for CH (26 cantons) or IT (national + patron saint)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        parameters: [
            new OA\Parameter(name: 'country', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['CH', 'IT'])),
            new OA\Parameter(name: 'year', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'canton_or_province_code', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'municipality_name', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'municipality_patron_saint_date', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'municipality_patron_saint_name', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Official holiday catalog')]
    )]
    public function catalog(Request $request): JsonResponse
    {
        $country = strtoupper((string) $request->query('country', 'CH'));
        $year = (int) $request->query('year', (string) date('Y'));
        $canton = $request->query('canton_or_province_code');
        $municipality = $request->query('municipality_name');
        $patronDate = $request->query('municipality_patron_saint_date');
        $patronName = $request->query('municipality_patron_saint_name');

        $entries = $this->catalogService->generateForJurisdiction(
            country: $country,
            year: $year,
            cantonOrProvinceCode: is_string($canton) ? $canton : null,
            municipalityName: is_string($municipality) ? $municipality : null,
            patronSaintMonthDay: is_string($patronDate) ? $patronDate : null,
            patronSaintName: is_string($patronName) ? $patronName : null,
        );

        return $this->successResponse([
            'country' => $country,
            'year' => $year,
            'cantons' => HolidayCatalogService::SWISS_CANTONS,
            'italian_patron_saints' => HolidayCatalogService::ITALIAN_PATRON_SAINTS,
            'holidays' => array_map(static fn ($entry): array => $entry->toArray(), $entries),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/leave-management/holiday-calendars',
        operationId: 'leaveMgmtListHolidayCalendars',
        summary: 'List tenant holiday calendars',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        responses: [new OA\Response(response: 200, description: 'Holiday calendars')]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $country = $request->query('country');
        $year = $request->query('year');
        $canton = $request->query('canton_or_province_code');

        $calendars = HolidayCalendar::query()
            ->where('tenant_id', $tenantId)
            ->when(is_string($country) && $country !== '', static fn ($q) => $q->where('country', strtoupper($country)))
            ->when(is_string($year) && $year !== '', static fn ($q) => $q->where('year', (int) $year))
            ->when(is_string($canton) && $canton !== '', static fn ($q) => $q->where('canton_or_province_code', strtoupper($canton)))
            ->orderByDesc('year')
            ->orderBy('country')
            ->orderBy('code')
            ->get();

        return $this->successResponse(HolidayCalendarResource::collection($calendars));
    }

    #[OA\Post(
        path: '/api/v1/leave-management/holiday-calendars',
        operationId: 'leaveMgmtStoreHolidayCalendar',
        summary: 'Create a holiday calendar (auto-populating official holidays when omitted)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        responses: [new OA\Response(response: 201, description: 'Holiday calendar created')]
    )]
    public function store(SaveHolidayCalendarRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $holidays = $validated['holidays'] ?? array_map(
            static fn ($entry): array => $entry->toArray(),
            $this->catalogService->generateForJurisdiction(
                country: (string) $validated['country'],
                year: (int) $validated['year'],
                cantonOrProvinceCode: $validated['canton_or_province_code'] ?? null,
                municipalityName: $validated['municipality_name'] ?? null,
                patronSaintMonthDay: $validated['municipality_patron_saint_date'] ?? null,
                patronSaintName: $validated['municipality_patron_saint_name'] ?? null,
                sundayRule: (string) ($validated['sunday_holiday_rule'] ?? 'compensatory_pay_or_day'),
            )
        );

        $calendar = HolidayCalendar::query()->create([
            'tenant_id' => $this->tenantId(),
            'code' => $validated['code'],
            'name' => $validated['name'],
            'country' => strtoupper((string) $validated['country']),
            'canton_or_province_code' => isset($validated['canton_or_province_code']) ? strtoupper((string) $validated['canton_or_province_code']) : null,
            'municipality_name' => $validated['municipality_name'] ?? null,
            'municipality_patron_saint_date' => $validated['municipality_patron_saint_date'] ?? null,
            'municipality_patron_saint_name' => $validated['municipality_patron_saint_name'] ?? null,
            'year' => (int) $validated['year'],
            'sunday_holiday_rule' => $validated['sunday_holiday_rule'] ?? 'compensatory_pay_or_day',
            'holidays' => $holidays,
            'working_days' => $validated['working_days'] ?? ['MON', 'TUE', 'WED', 'THU', 'FRI'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return $this->successResponse(new HolidayCalendarResource($calendar), __('leave_management.messages.calendar_created'), 201);
    }

    #[OA\Put(
        path: '/api/v1/leave-management/holiday-calendars/{id}',
        operationId: 'leaveMgmtUpdateHolidayCalendar',
        summary: 'Update a holiday calendar',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Holiday calendar updated')]
    )]
    public function update(SaveHolidayCalendarRequest $request, string $id): JsonResponse
    {
        $calendar = HolidayCalendar::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $validated = $request->validated();

        $calendar->update($validated);

        return $this->successResponse(new HolidayCalendarResource($calendar->fresh() ?? $calendar), __('leave_management.messages.calendar_updated'));
    }

    #[OA\Delete(
        path: '/api/v1/leave-management/holiday-calendars/{id}',
        operationId: 'leaveMgmtDeleteHolidayCalendar',
        summary: 'Delete a holiday calendar',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Holiday calendar deleted')]
    )]
    public function destroy(string $id): JsonResponse
    {
        Gate::authorize('manageConfiguration', HolidayCalendar::class);
        $calendar = HolidayCalendar::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $calendar->delete();

        return $this->successResponse(null, __('leave_management.messages.calendar_deleted'));
    }
}
