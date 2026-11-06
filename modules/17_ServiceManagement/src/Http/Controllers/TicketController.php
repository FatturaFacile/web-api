<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ServiceManagement\Contracts\TicketServiceInterface;
use Modules\ServiceManagement\DTOs\AssignTicketDTO;
use Modules\ServiceManagement\DTOs\CreateTicketDTO;
use Modules\ServiceManagement\DTOs\ResolveTicketDTO;
use Modules\ServiceManagement\Http\Requests\AssignTicketRequest;
use Modules\ServiceManagement\Http\Requests\ResolveTicketRequest;
use Modules\ServiceManagement\Http\Requests\StoreTicketRequest;
use Modules\ServiceManagement\Http\Resources\TicketResource;
use Modules\ServiceManagement\Models\Ticket;
use OpenApi\Attributes as OA;

final class TicketController extends Controller
{
    public function __construct(
        private readonly TicketServiceInterface $ticketService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the paginated list of tickets of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/esm/tickets',
        operationId: 'listEsmTickets',
        description: 'Returns the paginated list of tickets of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List tickets',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Tickets'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'priority', in: 'query', required: false, description: 'Filter by priority', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of tickets', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ServiceManagementTicketResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $query = Ticket::where('tenant_id', $tenant->id);

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->has('priority')) {
            $query->where('priority', $request->query('priority'));
        }

        $tickets = $query->orderByDesc('created_at')->paginate(25);

        return response()->json([
            'data' => TicketResource::collection($tickets),
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }

    /**
     * Creates a new ticket in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/esm/tickets',
        operationId: 'createEsmTicket',
        description: 'Creates a new ticket in the active tenant.',
        summary: 'Create ticket',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Tickets'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ServiceManagementStoreTicketRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Ticket created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementTicketResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreTicketRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $validated = $request->validated();

        $dto = new CreateTicketDTO(
            tenantId: $tenant->id,
            companyId: $tenant->id,
            title: $validated['title'],
            description: $validated['description'] ?? null,
            customerId: $validated['customer_id'] ?? null,
            contactEmail: $validated['contact_email'] ?? null,
            contactPhone: $validated['contact_phone'] ?? null,
            priority: $validated['priority'] ?? 'medium',
            serviceCategoryId: $validated['service_category_id'] ?? null,
            serviceContractId: $validated['service_contract_id'] ?? null,
            slaPolicyId: $validated['sla_policy_id'] ?? null,
            channel: $validated['channel'] ?? 'portal',
            assignedToId: $validated['assigned_to_id'] ?? null,
            serviceTeamId: $validated['service_team_id'] ?? null,
        );

        $ticket = $this->ticketService->createTicket($dto, $request->user()?->id);

        return response()->json([
            'message' => 'Ticket created successfully',
            'data' => new TicketResource($ticket),
        ], 201);
    }

    /**
     * Returns a single ticket of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/esm/tickets/{ticket}',
        operationId: 'getEsmTicket',
        description: 'Returns a single ticket of the active tenant.',
        summary: 'Get ticket',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Tickets'],
        parameters: [
            new OA\Parameter(name: 'ticket', in: 'path', required: true, description: 'Identifier of the ticket', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementTicketResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(Ticket $ticket): JsonResponse
    {
        return response()->json([
            'data' => new TicketResource($ticket),
        ]);
    }

    /**
     * Assigns a service ticket to an agent, with an optional service team.
     */
    #[OA\Post(
        path: '/api/v1/esm/tickets/{ticket}/assign',
        operationId: 'assignEsmTicket',
        description: 'Assigns a service ticket to an agent, with an optional service team.',
        summary: 'Assign ticket',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Tickets'],
        parameters: [
            new OA\Parameter(name: 'ticket', in: 'path', required: true, description: 'Identifier of the ticket', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ServiceManagementAssignTicketRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementTicketResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function assign(Ticket $ticket, AssignTicketRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $dto = new AssignTicketDTO(
            ticketId: $ticket->id,
            assignedToId: $validated['assigned_to_id'],
            serviceTeamId: $validated['service_team_id'] ?? null,
            assignedBy: $request->user()?->id
        );

        $updated = $this->ticketService->assignTicket($dto, $request->user()?->id);

        return response()->json([
            'message' => 'Ticket assigned successfully',
            'data' => new TicketResource($updated),
        ]);
    }

    /**
     * Resolves a service ticket with the resolution notes and root cause.
     */
    #[OA\Post(
        path: '/api/v1/esm/tickets/{ticket}/resolve',
        operationId: 'resolveEsmTicket',
        description: 'Resolves a service ticket with the resolution notes and root cause.',
        summary: 'Resolve ticket',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Tickets'],
        parameters: [
            new OA\Parameter(name: 'ticket', in: 'path', required: true, description: 'Identifier of the ticket', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ServiceManagementResolveTicketRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementTicketResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function resolve(Ticket $ticket, ResolveTicketRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $dto = new ResolveTicketDTO(
            ticketId: $ticket->id,
            resolutionNotes: $validated['resolution_notes'] ?? null,
            rootCause: $validated['root_cause'] ?? null,
            resolvedBy: $request->user()?->id,
            knowledgeArticleId: $validated['knowledge_article_id'] ?? null
        );

        $updated = $this->ticketService->resolveTicket($dto, $request->user()?->id);

        return response()->json([
            'message' => 'Ticket resolved successfully',
            'data' => new TicketResource($updated),
        ]);
    }

    /**
     * Closes a service ticket.
     */
    #[OA\Post(
        path: '/api/v1/esm/tickets/{ticket}/close',
        operationId: 'closeEsmTicket',
        description: 'Closes a service ticket.',
        summary: 'Close ticket',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Tickets'],
        parameters: [
            new OA\Parameter(name: 'ticket', in: 'path', required: true, description: 'Identifier of the ticket', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementTicketResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function close(Ticket $ticket, Request $request): JsonResponse
    {
        $updated = $this->ticketService->closeTicket($ticket->id, $request->user()?->id ?? 'system', $request->user()?->id);

        return response()->json([
            'message' => 'Ticket closed successfully',
            'data' => new TicketResource($updated),
        ]);
    }
}
