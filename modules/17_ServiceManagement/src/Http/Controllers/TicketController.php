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
use Modules\ServiceManagement\DTOs\EscalateTicketDTO;
use Modules\ServiceManagement\DTOs\ResolveTicketDTO;
use Modules\ServiceManagement\Http\Requests\AssignTicketRequest;
use Modules\ServiceManagement\Http\Requests\ResolveTicketRequest;
use Modules\ServiceManagement\Http\Requests\StoreTicketRequest;
use Modules\ServiceManagement\Http\Resources\TicketResource;
use Modules\ServiceManagement\Models\Ticket;

final class TicketController extends Controller
{
    public function __construct(
        private readonly TicketServiceInterface $ticketService,
        private readonly TenantContext $tenantContext
    ) {}

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

    public function show(Ticket $ticket): JsonResponse
    {
        return response()->json([
            'data' => new TicketResource($ticket),
        ]);
    }

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

    public function close(Ticket $ticket, Request $request): JsonResponse
    {
        $updated = $this->ticketService->closeTicket($ticket->id, $request->user()?->id ?? 'system', $request->user()?->id);

        return response()->json([
            'message' => 'Ticket closed successfully',
            'data' => new TicketResource($updated),
        ]);
    }
}
