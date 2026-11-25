<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\PassengerTransport\Http\Controllers\Concerns\InteractsWithPassengerTransport;
use Modules\PassengerTransport\Http\Requests\ListFiltersRequest;
use Modules\PassengerTransport\Models\PassengerBooking;
use Modules\PassengerTransport\Services\DispatchTimelineService;
use OpenApi\Attributes as OA;

final class DispatchController extends Controller
{
    use ApiResponse;
    use InteractsWithPassengerTransport;

    #[OA\Get(path: '/api/v1/passenger-transport/dispatch/timeline', operationId: 'passengerTransportDispatchTimeline', summary: 'Vehicles and drivers against time, with flight status and driving-time gauges', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Passenger Transport'], responses: [new OA\Response(response: 200, description: 'Dispatch timeline')])]
    public function timeline(ListFiltersRequest $request, DispatchTimelineService $timeline): JsonResponse
    {
        Gate::authorize('viewAny', PassengerBooking::class);
        $from = $request->filled('from') ? CarbonImmutable::parse((string) $request->input('from')) : CarbonImmutable::now()->startOfDay();
        $to = $request->filled('to') ? CarbonImmutable::parse((string) $request->input('to')) : $from->addDay();

        return $this->successResponse($timeline->timeline($from, $to), $this->message('loaded'));
    }
}
