<?php

declare(strict_types=1);

namespace Modules\Visitors\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Visitors\Http\Requests\CheckInRequest;
use Modules\Visitors\Http\Requests\VisitRequest;
use Modules\Visitors\Http\Resources\VisitResource;
use Modules\Visitors\Models\VisitorVisit;
use Modules\Visitors\Policies\VisitorsPolicy;
use Modules\Visitors\Services\VisitorService;
use OpenApi\Attributes as OA;

/**
 * Expected visits, check-in and check-out at reception, and the list of who is on site.
 */
final class VisitController extends Controller
{
    public function __construct(private readonly VisitorService $visits) {}

    #[OA\Get(path: '/api/v1/visitors/visits', operationId: 'listVisitorVisits', description: 'Return the visits of a day (default today, company time zone), optionally by status. Reception sees every visit, other members those they host.', summary: 'List visits', security: [['bearerAuth' => []]], tags: ['Visitors'], parameters: [
        new OA\Parameter(name: 'date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['expected', 'checked_in', 'checked_out', 'cancelled'])),
    ], responses: [
        new OA\Response(response: 200, description: 'Visits', content: new OA\JsonContent(ref: '#/components/schemas/VisitorsVisitCollection')),
        new OA\Response(ref: '#/components/responses/VisitorsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/VisitorsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/VisitorsUnprocessable', response: 422),
    ])]
    public function index(Request $request, VisitorsPolicy $policy, TenantClock $clock): AnonymousResourceCollection
    {
        $this->authorize('viewAny', VisitorVisit::class);
        $filters = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'status' => ['nullable', Rule::in(VisitorVisit::Statuses)]]);

        $day = isset($filters['date']) ? CarbonImmutable::parse($filters['date'], $clock->timezone()) : $clock->today();

        $visits = VisitorVisit::query()
            ->with(['visitor', 'host'])
            ->whereBetween('expected_at', [$day->startOfDay()->utc(), $day->endOfDay()->utc()])
            ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
            ->when(! $policy->isReception($request->user()), static fn ($query) => $query->where('host_user_id', $request->user()->id))
            ->orderBy('expected_at')
            ->get();

        return VisitResource::collection($visits);
    }

    #[OA\Post(path: '/api/v1/visitors/visits', operationId: 'storeVisitorVisit', description: 'Announce an expected visit, for a known visitor or with the data of a new one. A member without reception rights can only announce their own guests.', summary: 'Announce visit', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/VisitorsVisitRequest')), tags: ['Visitors'], responses: [
        new OA\Response(response: 201, description: 'Visit announced', content: new OA\JsonContent(ref: '#/components/schemas/VisitorsVisitResponse')),
        new OA\Response(ref: '#/components/responses/VisitorsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/VisitorsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/VisitorsUnprocessable', response: 422),
    ])]
    public function store(VisitRequest $request, VisitorsPolicy $policy): JsonResponse
    {
        $this->authorize('create', VisitorVisit::class);
        $data = $request->validated();

        if (! $policy->isReception($request->user()) && (string) $data['host_user_id'] !== (string) $request->user()->id) {
            throw ValidationException::withMessages(['host_user_id' => 'You can only announce your own visitors.']);
        }

        return (new VisitResource($this->visits->expect($data)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/visitors/visits/{id}', operationId: 'showVisitorVisit', description: 'Return a visit with its visitor and host.', summary: 'Show visit', security: [['bearerAuth' => []]], tags: ['Visitors'], parameters: [new OA\Parameter(ref: '#/components/parameters/VisitorsId')], responses: [
        new OA\Response(response: 200, description: 'Visit', content: new OA\JsonContent(ref: '#/components/schemas/VisitorsVisitResponse')),
        new OA\Response(ref: '#/components/responses/VisitorsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/VisitorsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/VisitorsNotFound', response: 404),
    ])]
    public function show(string $id): VisitResource
    {
        $visit = VisitorVisit::query()->with(['visitor', 'host'])->findOrFail($id);
        $this->authorize('view', $visit);

        return new VisitResource($visit);
    }

    #[OA\Post(path: '/api/v1/visitors/visits/{id}/check-in', operationId: 'checkInVisitorVisit', description: 'Hand a badge to an expected visitor and tell the host by mail. A badge is worn by one visitor at a time. Reception only.', summary: 'Check in', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/VisitorsCheckInRequest')), tags: ['Visitors'], parameters: [new OA\Parameter(ref: '#/components/parameters/VisitorsId')], responses: [
        new OA\Response(response: 200, description: 'Visitor checked in', content: new OA\JsonContent(ref: '#/components/schemas/VisitorsVisitResponse')),
        new OA\Response(ref: '#/components/responses/VisitorsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/VisitorsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/VisitorsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/VisitorsUnprocessable', response: 422),
    ])]
    public function checkIn(CheckInRequest $request, string $id): VisitResource
    {
        $visit = VisitorVisit::query()->findOrFail($id);
        $this->authorize('receive', $visit);

        return new VisitResource($this->visits->checkIn($visit, (string) $request->validated('badge_number')));
    }

    #[OA\Post(path: '/api/v1/visitors/visits/{id}/check-out', operationId: 'checkOutVisitorVisit', description: 'Record that the visitor left and the badge came back. Reception only.', summary: 'Check out', security: [['bearerAuth' => []]], tags: ['Visitors'], parameters: [new OA\Parameter(ref: '#/components/parameters/VisitorsId')], responses: [
        new OA\Response(response: 200, description: 'Visitor checked out', content: new OA\JsonContent(ref: '#/components/schemas/VisitorsVisitResponse')),
        new OA\Response(ref: '#/components/responses/VisitorsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/VisitorsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/VisitorsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/VisitorsUnprocessable', response: 422),
    ])]
    public function checkOut(string $id): VisitResource
    {
        $visit = VisitorVisit::query()->findOrFail($id);
        $this->authorize('receive', $visit);

        return new VisitResource($this->visits->checkOut($visit));
    }

    #[OA\Post(path: '/api/v1/visitors/visits/{id}/cancel', operationId: 'cancelVisitorVisit', description: 'Call off an expected visit. Reception or the host.', summary: 'Cancel visit', security: [['bearerAuth' => []]], tags: ['Visitors'], parameters: [new OA\Parameter(ref: '#/components/parameters/VisitorsId')], responses: [
        new OA\Response(response: 200, description: 'Visit cancelled', content: new OA\JsonContent(ref: '#/components/schemas/VisitorsVisitResponse')),
        new OA\Response(ref: '#/components/responses/VisitorsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/VisitorsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/VisitorsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/VisitorsUnprocessable', response: 422),
    ])]
    public function cancel(string $id): VisitResource
    {
        $visit = VisitorVisit::query()->findOrFail($id);
        $this->authorize('cancel', $visit);

        return new VisitResource($this->visits->cancel($visit));
    }

    #[OA\Get(path: '/api/v1/visitors/on-site', operationId: 'listVisitorsOnSite', description: 'Who is in the building now, oldest arrival first: the list for an evacuation. Reception only.', summary: 'Visitors on site', security: [['bearerAuth' => []]], tags: ['Visitors'], responses: [
        new OA\Response(response: 200, description: 'Visitors on site', content: new OA\JsonContent(ref: '#/components/schemas/VisitorsVisitCollection')),
        new OA\Response(ref: '#/components/responses/VisitorsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/VisitorsForbidden', response: 403),
    ])]
    public function onSite(): AnonymousResourceCollection
    {
        $this->authorize('receive', VisitorVisit::class);

        return VisitResource::collection($this->visits->onSite());
    }
}
