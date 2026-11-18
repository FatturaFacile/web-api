<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StorePortalTicketRequest;
use App\Http\Resources\Portal\PortalHelpdeskTicketResource;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\PortalAccessToken;
use App\Models\SalesOrder;
use App\Services\Portal\PortalTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\ServiceManagement\DTOs\CreateTicketDTO;
use Modules\ServiceManagement\Models\Ticket as HelpdeskTicket;
use Modules\ServiceManagement\Services\Helpdesk\HelpdeskThreadService;
use Modules\ServiceManagement\Services\TicketService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Support requests of the portal customer: inquiries, invoice disputes and defective-goods (RMA) reports, optionally
 * linked to one of their own invoices, orders or delivery notes, with attachments. They are tickets of the service
 * module (esm_tickets), so the helpdesk works them like any other request.
 */
class PortalSupportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TicketService $tickets,
        private readonly HelpdeskThreadService $thread,
        private readonly PortalTokenService $tokens,
    ) {}

    #[OA\Get(path: '/api/v1/portal/tickets', operationId: 'listPortalSessionTickets', summary: 'List my support requests', description: 'Helpdesk tickets of the signed-in customer with their public messages.', security: [['portalBearer' => []]], tags: ['Customer Portal'], responses: [new OA\Response(response: 200, description: 'Tickets', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 401, description: 'Not signed in', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function index(Request $request): JsonResponse
    {
        $session = $this->session($request);

        $tickets = HelpdeskTicket::query()
            ->where('customer_id', $session->contact_id)
            ->with(['messages' => fn ($query) => $query->public()->orderBy('created_at')])
            ->latest('created_at')
            ->limit(100)
            ->get();

        return $this->successResponse(
            data: PortalHelpdeskTicketResource::collection($tickets),
            message: 'Support tickets retrieved successfully',
        );
    }

    #[OA\Post(path: '/api/v1/portal/tickets', operationId: 'createPortalSessionTicket', summary: 'Open a support request', description: 'Creates a ticket. Multipart when files are attached. The linked invoice, order or delivery note must belong to the customer.', security: [['portalBearer' => []]], tags: ['Customer Portal'], requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['kind', 'subject', 'description'], properties: [new OA\Property(property: 'kind', type: 'string', enum: ['inquiry', 'invoice_dispute', 'defective_goods']), new OA\Property(property: 'subject', type: 'string'), new OA\Property(property: 'description', type: 'string'), new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'order_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'ddt_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'attachments', type: 'array', items: new OA\Items(type: 'string', format: 'binary'))]))), responses: [new OA\Response(response: 201, description: 'Ticket created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function store(StorePortalTicketRequest $request): JsonResponse
    {
        $session = $this->session($request);
        /** @var Contact $contact */
        $contact = Contact::query()->findOrFail($session->contact_id);
        $data = $request->validated();

        $related = $this->ownedLinks($session, $data);
        $kind = (string) $data['kind'];

        $ticket = $this->tickets->createTicket(new CreateTicketDTO(
            tenantId: $session->tenant_id,
            companyId: $session->tenant_id,
            title: (string) $data['subject'],
            description: (string) $data['description'],
            customerId: $contact->id,
            contactEmail: $contact->email,
            priority: $kind === 'defective_goods' ? 'high' : 'medium',
            channel: 'portal',
            metadata: ['portal_request_kind' => $kind, 'related' => $related],
            tags: [$kind],
        ));

        $this->thread->addCustomerMessage(
            ticket: $ticket,
            body: (string) $data['description'],
            contact: $contact,
            channel: 'portal',
            attachments: array_values($request->file('attachments', [])),
        );

        $this->tokens->logActivity($session, 'ticket_created', 'ticket', $ticket->id, ['kind' => $kind, 'related' => $related]);

        return $this->successResponse(
            data: PortalHelpdeskTicketResource::make($ticket->load(['messages' => fn ($query) => $query->public()->orderBy('created_at')])),
            message: __('portal.messages.ticket_created'),
            statusCode: Response::HTTP_CREATED,
        );
    }

    /**
     * Only documents of the customer can be linked: any other id is refused, so ids of other customers are never probed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function ownedLinks(PortalAccessToken $session, array $data): array
    {
        $links = [
            'invoice_id' => Invoice::class,
            'order_id' => SalesOrder::class,
            'ddt_id' => DeliveryNote::class,
        ];

        $related = [];

        foreach ($links as $field => $model) {
            if (empty($data[$field])) {
                continue;
            }

            if (! $model::query()->where('contact_id', $session->contact_id)->whereKey($data[$field])->exists()) {
                throw ValidationException::withMessages([$field => __('portal.errors.ticket_target_invalid')]);
            }

            $related[$field] = (string) $data[$field];
        }

        return $related;
    }

    private function session(Request $request): PortalAccessToken
    {
        /** @var PortalAccessToken */
        return $request->attributes->get('portal_token');
    }
}
