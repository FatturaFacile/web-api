<?php

declare(strict_types=1);

namespace Modules\Visitors\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Visitors\Http\Requests\KioskCheckInRequest;
use Modules\Visitors\Http\Requests\KioskCheckOutRequest;
use Modules\Visitors\Http\Resources\KioskVisitResource;
use Modules\Visitors\Models\VisitorKiosk;
use Modules\Visitors\Models\VisitorVisit;
use Modules\Visitors\Services\VisitorService;
use OpenApi\Attributes as OA;

/**
 * The reception tablet. It authenticates with its own key and secret; the tenant comes from the kiosk, never from the
 * client. It sees today's expected visitors by first name and initial only.
 */
final class KioskApiController extends Controller
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly VisitorService $visits) {}

    /**
     * Kiosk: expected today.
     */
    #[OA\Get(path: '/api/v1/visitor-kiosk/expected', operationId: 'kioskExpectedVisits', description: 'Visits expected today (company time zone) that have not checked in yet, by first name and initial, for the visitor to pick theirs.', summary: 'Kiosk: expected today', tags: ['Visitors'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/VisitorsKioskKey'),
        new OA\Parameter(ref: '#/components/parameters/VisitorsKioskSecret'),
    ], responses: [
        new OA\Response(response: 200, description: 'Expected visits', content: new OA\JsonContent(ref: '#/components/schemas/VisitorsKioskVisitCollection')),
        new OA\Response(ref: '#/components/responses/VisitorsKioskUnauthenticated', response: 401),
    ])]
    public function expected(TenantClock $clock): AnonymousResourceCollection
    {
        $today = $clock->today();

        return KioskVisitResource::collection(VisitorVisit::query()
            ->with(['visitor', 'host'])
            ->where('status', VisitorVisit::Expected)
            ->whereBetween('expected_at', [$today->startOfDay()->utc(), $today->endOfDay()->utc()])
            ->orderBy('expected_at')
            ->get());
    }

    /**
     * Kiosk: check in.
     */
    #[OA\Post(path: '/api/v1/visitor-kiosk/check-in', operationId: 'kioskCheckIn', description: 'Check in an expected visit of the company of the kiosk, or a walk-in visitor with the host to call. The host is told by mail.', summary: 'Kiosk: check in', requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/VisitorsKioskCheckInRequest')), tags: ['Visitors'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/VisitorsKioskKey'),
        new OA\Parameter(ref: '#/components/parameters/VisitorsKioskSecret'),
    ], responses: [
        new OA\Response(response: 201, description: 'Visitor checked in', content: new OA\JsonContent(ref: '#/components/schemas/VisitorsKioskVisitResponse')),
        new OA\Response(ref: '#/components/responses/VisitorsKioskUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/VisitorsUnprocessable', response: 422),
        new OA\Response(ref: '#/components/responses/VisitorsMaintenance', response: 503),
    ])]
    public function checkIn(KioskCheckInRequest $request): JsonResponse
    {
        $kiosk = $this->kiosk($request);
        $data = $request->validated();

        $visit = isset($data['visit_id'])
            ? $this->visits->checkIn(VisitorVisit::query()->findOrFail($data['visit_id']), (string) $data['badge_number'], $kiosk)
            : $this->visits->walkIn($data['visitor'], (string) $data['host_user_id'], (string) $data['badge_number'], $data['purpose'] ?? null, $kiosk);

        return (new KioskVisitResource($visit))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Kiosk: check out.
     */
    #[OA\Post(path: '/api/v1/visitor-kiosk/check-out', operationId: 'kioskCheckOut', description: 'Check out the visitor wearing the badge given back.', summary: 'Kiosk: check out', requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/VisitorsKioskCheckOutRequest')), tags: ['Visitors'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/VisitorsKioskKey'),
        new OA\Parameter(ref: '#/components/parameters/VisitorsKioskSecret'),
    ], responses: [
        new OA\Response(response: 200, description: 'Visitor checked out', content: new OA\JsonContent(ref: '#/components/schemas/VisitorsKioskVisitResponse')),
        new OA\Response(ref: '#/components/responses/VisitorsKioskUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/VisitorsUnprocessable', response: 422),
        new OA\Response(ref: '#/components/responses/VisitorsMaintenance', response: 503),
    ])]
    public function checkOut(KioskCheckOutRequest $request): KioskVisitResource
    {
        return new KioskVisitResource($this->visits->checkOutByBadge((string) $request->validated('badge_number')));
    }

    private function kiosk(Request $request): VisitorKiosk
    {
        /** @var VisitorKiosk $kiosk */
        $kiosk = $request->attributes->get('visitorKiosk');

        return $kiosk;
    }
}
