<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\AdventureParks\Http\Controllers\Concerns\ResolvesParkContext;
use Modules\AdventureParks\Http\Requests\AssignPpeRequest;
use Modules\AdventureParks\Http\Requests\BookTicketRequest;
use Modules\AdventureParks\Http\Requests\BriefingRequest;
use Modules\AdventureParks\Http\Requests\OperateActionRequest;
use Modules\AdventureParks\Http\Requests\ReturnPpeRequest;
use Modules\AdventureParks\Http\Requests\TicketIndexRequest;
use Modules\AdventureParks\Http\Resources\TicketResource;
use Modules\AdventureParks\Models\Ticket;
use Modules\AdventureParks\Services\PpeDeskService;
use Modules\AdventureParks\Services\TicketBookingService;
use OpenApi\Attributes as OA;

/**
 * Timed-slot tickets and the dressing desk (assign, start, return).
 */
final class TicketController extends Controller
{
    use ApiResponse;
    use ResolvesParkContext;

    public function __construct(
        private readonly TicketBookingService $booking,
        private readonly PpeDeskService $desk,
    ) {}

    #[OA\Get(path: '/api/v1/adventure-parks/tickets', operationId: 'adventureParksTicketsList', summary: 'List the tickets of a day or a status', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], responses: [new OA\Response(response: 200, description: 'Tickets')])]
    public function index(TicketIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return $this->successResponse(TicketResource::collection($this->booking->list($validated['date'] ?? null, $validated['status'] ?? null)));
    }

    #[OA\Post(path: '/api/v1/adventure-parks/tickets', operationId: 'adventureParksTicketsBook', summary: 'Book a timed slot (locks the harness stock, 422 slot_sold_out when exhausted)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], responses: [new OA\Response(response: 201, description: 'Ticket booked'), new OA\Response(response: 422, description: 'Slot sold out or closed')])]
    public function store(BookTicketRequest $request): JsonResponse
    {
        /** @var array{slot_id: string, slot_date: string, contact_name: string, lines: list<array{size: string, quantity: int}>} $data */
        $data = $request->validated();

        return $this->successResponse(new TicketResource($this->booking->book($data, $this->userId($request))), $this->message('ticket_booked'), 201);
    }

    #[OA\Get(path: '/api/v1/adventure-parks/tickets/by-code/{codeOrToken}', operationId: 'adventureParksTicketsByCode', summary: 'Scanner lookup by booking code or public token', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'codeOrToken', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Ticket')])]
    public function byCode(string $codeOrToken): JsonResponse
    {
        Gate::authorize('viewAny', Ticket::class);

        return $this->successResponse(new TicketResource($this->booking->findByCodeOrToken($codeOrToken)));
    }

    #[OA\Get(path: '/api/v1/adventure-parks/tickets/{id}', operationId: 'adventureParksTicketsShow', summary: 'Show a ticket with participants and equipment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Ticket')])]
    public function show(string $id): JsonResponse
    {
        Gate::authorize('viewAny', Ticket::class);

        return $this->successResponse(new TicketResource($this->booking->fresh($id)));
    }

    #[OA\Post(path: '/api/v1/adventure-parks/tickets/{id}/assign-ppe', operationId: 'adventureParksTicketsAssignPpe', summary: 'Hand a PPE item to a participant by barcode', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Ticket with the assignment'), new OA\Response(response: 422, description: 'waiver_missing, ppe_not_found, ppe_blocked or ppe_size_mismatch')])]
    public function assignPpe(AssignPpeRequest $request, string $id): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse(new TicketResource($this->desk->assign($id, (string) $data['participant_id'], (string) $data['barcode'], $this->userId($request))), $this->message('ppe_assigned'));
    }

    #[OA\Post(path: '/api/v1/adventure-parks/tickets/{id}/start-course', operationId: 'adventureParksTicketsStartCourse', summary: 'Send an equipped group on the course', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Ticket on course')])]
    public function startCourse(OperateActionRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new TicketResource($this->desk->startCourse($id)), $this->message('course_started'));
    }

    #[OA\Post(path: '/api/v1/adventure-parks/tickets/{id}/no-show', operationId: 'adventureParksTicketsNoShow', summary: 'Mark a ticket as no-show and free its stock', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Ticket marked no-show')])]
    public function noShow(OperateActionRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new TicketResource($this->desk->noShow($id)), $this->message('ticket_no_show'));
    }

    #[OA\Post(path: '/api/v1/adventure-parks/tickets/{id}/return-ppe', operationId: 'adventureParksTicketsReturnPpe', summary: 'Take the PPE back: rental cycle and usage hours of each item', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Ticket')])]
    public function returnPpe(ReturnPpeRequest $request, string $id): JsonResponse
    {
        $data = $request->validated();
        $hours = $data['usage_hours'] ?? null;

        return $this->successResponse(new TicketResource($this->desk->returnPpe($id, $data['barcodes'] ?? null, $hours === null ? null : (float) $hours)), $this->message('ppe_returned'));
    }

    #[OA\Post(path: '/api/v1/adventure-parks/tickets/{id}/briefing', operationId: 'adventureParksTicketsBriefing', summary: 'Record the safety briefing given by an instructor', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Ticket')])]
    public function briefing(BriefingRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new TicketResource($this->desk->completeBriefing($id, (string) $request->validated()['instructor_id'])), $this->message('briefing_done'));
    }
}
