<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Modules\Facility\Http\Controllers\Concerns\InteractsWithFacility;
use Modules\Facility\Http\Requests\BookingRuleRequest;
use Modules\Facility\Http\Resources\BookingRuleResource;
use Modules\Facility\Models\FacilityBookingRule;
use Modules\Facility\Models\FacilitySpace;
use Modules\Facility\Services\BookingService;

/**
 * Booking limits per kind of space. Operations are documented in FacilityBookingOpenApi.
 */
final class BookingRuleController extends Controller
{
    use InteractsWithFacility;

    public function __construct(private readonly BookingService $bookings) {}

    /**
     * The limits in force for every bookable kind, and whether the company changed the default.
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', FacilityBookingRule::class);
        $customized = FacilityBookingRule::query()->pluck('space_kind')->all();

        return response()->json(['data' => array_map(
            fn (string $kind): array => ['space_kind' => $kind, ...$this->bookings->rulesFor($kind), 'customized' => in_array($kind, $customized, true)],
            FacilitySpace::BookableKinds,
        )]);
    }

    public function update(BookingRuleRequest $request, string $kind): JsonResponse
    {
        validator(['kind' => $kind], ['kind' => [Rule::in(FacilitySpace::BookableKinds)]])->validate();
        $this->authorize('manage', FacilityBookingRule::class);

        return (new BookingRuleResource($this->bookings->saveRule($kind, $request->validated())))->response()->setStatusCode(200);
    }
}
