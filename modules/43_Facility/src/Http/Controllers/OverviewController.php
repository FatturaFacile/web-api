<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantClock;
use Illuminate\Http\JsonResponse;
use Modules\Facility\Http\Controllers\Concerns\InteractsWithFacility;
use Modules\Facility\Models\FacilityBooking;
use Modules\Facility\Models\FacilityBuilding;
use Modules\Facility\Models\FacilityInspection;
use Modules\Facility\Models\FacilitySpace;
use Modules\Facility\Policies\FacilityPolicy;
use Modules\Facility\Services\ServiceRequestService;

/**
 * Counters for the facility start page. Operations are documented in FacilityRegisterOpenApi.
 */
final class OverviewController extends Controller
{
    use InteractsWithFacility;

    public function __construct(private readonly ServiceRequestService $requests, private readonly FacilityPolicy $policy, private readonly TenantClock $clock) {}

    public function show(): JsonResponse
    {
        $this->authorize('viewAny', FacilityBuilding::class);
        $operator = $this->policy->isOperator($this->actor());
        $today = $this->clock->today();

        $inspections = $operator
            ? [
                'open' => FacilityInspection::query()->whereIn('status', FacilityInspection::OpenStatuses)->count(),
                'overdue' => FacilityInspection::query()->whereIn('status', FacilityInspection::OpenStatuses)->whereDate('due_date', '<', $today->toDateString())->count(),
            ]
            : ['open' => 0, 'overdue' => 0];

        return response()->json(['data' => [
            'buildings' => FacilityBuilding::query()->where('is_active', true)->count(),
            'spaces' => FacilitySpace::query()->where('is_active', true)->count(),
            'bookable_spaces' => FacilitySpace::query()->where('is_active', true)->where('is_bookable', true)->count(),
            'bookings_today' => FacilityBooking::query()->whereIn('status', FacilityBooking::OccupyingStatuses)->where('starts_at', '>=', $today->utc())->where('starts_at', '<', $today->addDay()->utc())->count(),
            'my_upcoming_bookings' => FacilityBooking::query()->where('user_id', $this->actorId())->where('status', FacilityBooking::Booked)->where('ends_at', '>', now())->count(),
            'requests' => $this->requests->board($operator ? null : $this->actorId()),
            'inspections' => $inspections,
            'requests_sla_hours' => config('facility.sla_hours'),
        ]]);
    }
}
