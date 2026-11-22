<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\ServiceManagement\DTOs\CompleteWorkOrderDTO;
use Modules\ServiceManagement\Http\Requests\CompleteAndBillWorkOrderRequest;
use Modules\ServiceManagement\Http\Requests\EscalateTicketToFieldServiceRequest;
use Modules\ServiceManagement\Http\Resources\TicketResource;
use Modules\ServiceManagement\Http\Resources\WorkOrderResource;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\WorkOrder;
use Modules\ServiceManagement\Services\FieldInterventionBillingService;
use Modules\ServiceManagement\Services\TicketFieldEscalationService;
use OpenApi\Attributes as OA;

/**
 * Cross-module hand-offs of the customer lifecycle: helpdesk ticket -> field intervention -> ticket resolution and
 * settlement (contract hours or invoice draft).
 */
final class CustomerLifecycleController extends Controller
{
    public function __construct(
        private readonly TicketFieldEscalationService $escalation,
        private readonly FieldInterventionBillingService $billing,
    ) {}

    /**
     * Escalates a ticket to a field service work order.
     */
    #[OA\Post(
        path: '/api/v1/esm/tickets/{ticket}/escalate-to-field-service',
        operationId: 'escalateEsmTicketToFieldService',
        description: 'Creates a field service work order from an on-site ticket. The work order inherits the customer, installed asset, maintenance contract, SLA resolution deadline, priority, service address and the diagnostic notes, and the ticket is escalated. Replaying the call returns the open work order of the ticket (200) instead of creating a second one (201).',
        summary: 'Escalate a ticket to field service',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Service Management'],
        parameters: [
            new OA\Parameter(name: 'ticket', in: 'path', required: true, description: 'Identifier of the ticket', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'diagnostic_notes', type: 'string', nullable: true),
            new OA\Property(property: 'scheduled_start', type: 'string', format: 'date-time', nullable: true),
            new OA\Property(property: 'technician_id', type: 'string', format: 'uuid', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Work order created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'work_order', type: 'object'), new OA\Property(property: 'ticket', type: 'object'), new OA\Property(property: 'created', type: 'boolean')])])),
            new OA\Response(response: 200, description: 'The ticket already has an open work order', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden: read-only role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Ticket not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Ticket already resolved, closed or cancelled', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function escalateToFieldService(Ticket $ticket, EscalateTicketToFieldServiceRequest $request): JsonResponse
    {
        $result = $this->escalation->escalate(
            $ticket,
            $request->validated('diagnostic_notes'),
            $request->validated('scheduled_start'),
            $request->validated('technician_id'),
            $request->user()?->id,
        );

        return response()->json([
            'message' => __($result['created'] ? 'customer_360.messages.escalated' : 'customer_360.messages.escalation_exists'),
            'data' => [
                'work_order' => new WorkOrderResource($result['work_order']),
                'ticket' => new TicketResource($ticket->refresh()),
                'created' => $result['created'],
            ],
        ], $result['created'] ? 201 : 200);
    }

    /**
     * Completes a signed intervention, resolves its ticket and settles contract hours or an invoice draft.
     */
    #[OA\Post(
        path: '/api/v1/fsm/work-orders/{workOrder}/complete-and-bill',
        operationId: 'completeAndBillFsmWorkOrder',
        description: 'Completes a field intervention with the customer signature, resolves the originating ticket and settles it exactly once: labor hours are deducted from the covering maintenance contract when there is one, otherwise a sales invoice draft is created from the billable items. Replaying the call never double-books hours or double-invoices.',
        summary: 'Complete an intervention and bill it',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Field Service'],
        parameters: [
            new OA\Parameter(name: 'workOrder', in: 'path', required: true, description: 'Identifier of the work order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['tax_rate'], properties: [
            new OA\Property(property: 'tax_rate', description: 'VAT rate in percent applied to every invoice line', type: 'string', example: '22'),
            new OA\Property(property: 'completion_notes', type: 'string', nullable: true),
            new OA\Property(property: 'resolution_summary', type: 'string', nullable: true),
            new OA\Property(property: 'customer_signature_url', type: 'string', nullable: true),
            new OA\Property(property: 'signed_by_customer_name', type: 'string', nullable: true),
            new OA\Property(property: 'signed_at', type: 'string', format: 'date-time', nullable: true),
            new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'revenue_account_id', type: 'string', format: 'uuid', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Intervention completed and settled', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'work_order', type: 'object'), new OA\Property(property: 'ticket_status', type: 'string', nullable: true), new OA\Property(property: 'settlement', type: 'string', enum: ['contract_deduction', 'invoice_draft', 'none']), new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'hours_deducted', type: 'string', example: '2.50')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden: read-only role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Work order not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid payload or nothing billable', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function completeAndBill(WorkOrder $workOrder, CompleteAndBillWorkOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->billing->completeAndBill(
            new CompleteWorkOrderDTO(
                workOrderId: (string) $workOrder->id,
                completionNotes: $validated['completion_notes'] ?? null,
                resolutionSummary: $validated['resolution_summary'] ?? null,
                customerSignatureUrl: $validated['customer_signature_url'] ?? null,
                signedByCustomerName: $validated['signed_by_customer_name'] ?? null,
                signedAt: $validated['signed_at'] ?? null,
            ),
            (string) $validated['tax_rate'],
            $validated['due_date'] ?? null,
            $validated['revenue_account_id'] ?? null,
            $request->user()?->id,
        );

        return response()->json([
            'message' => __('customer_360.messages.completed'),
            'data' => [
                'work_order' => new WorkOrderResource($result['work_order']),
                'ticket_status' => $result['ticket']?->status,
                'settlement' => $result['settlement'],
                'invoice_id' => $result['invoice']?->id,
                'hours_deducted' => $result['hours_deducted'],
            ],
        ]);
    }
}
