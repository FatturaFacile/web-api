<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\ServiceManagement\Http\Requests\HandoffToFieldServiceRequest;
use Modules\ServiceManagement\Http\Requests\HandoffToRmaRequest;
use Modules\ServiceManagement\Http\Requests\HelpdeskInsightsRequest;
use Modules\ServiceManagement\Http\Requests\HolidayPreviewRequest;
use Modules\ServiceManagement\Http\Requests\LogTicketTimeRequest;
use Modules\ServiceManagement\Http\Requests\SaveEscalationChainRequest;
use Modules\ServiceManagement\Http\Requests\UpdateSlaPolicySettingsRequest;
use Modules\ServiceManagement\Http\Resources\EscalationChainResource;
use Modules\ServiceManagement\Http\Resources\RmaOrderResource;
use Modules\ServiceManagement\Http\Resources\TicketEscalationLogResource;
use Modules\ServiceManagement\Http\Resources\TicketTimeEntryResource;
use Modules\ServiceManagement\Http\Resources\WorkOrderResource;
use Modules\ServiceManagement\Models\BusinessHoursCalendar;
use Modules\ServiceManagement\Models\EscalationChain;
use Modules\ServiceManagement\Models\HelpdeskSetting;
use Modules\ServiceManagement\Models\SLAPolicy;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketEscalationLog;
use Modules\ServiceManagement\Models\TicketTimeEntry;
use Modules\ServiceManagement\Services\Helpdesk\EscalationChainWriter;
use Modules\ServiceManagement\Services\Helpdesk\HelpdeskInsightsService;
use Modules\ServiceManagement\Services\Helpdesk\HolidayCatalog;
use Modules\ServiceManagement\Services\Helpdesk\TicketHandoffService;
use Modules\ServiceManagement\Services\Helpdesk\TicketTimeLogger;
use OpenApi\Attributes as OA;

/**
 * SLA configuration and the agent workbench of the helpdesk (Mod 083, #195, #43): escalation chains, SLA policy settings,
 * holiday preview, the breach risk queue, CSAT and MTTR metrics, the 360 degree customer context, time logging with the
 * contract hour deduction, and the one-click handoff of a ticket to a work order or an RMA.
 */
final class HelpdeskSlaController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(path: '/api/v1/esm/helpdesk/escalation-chains', operationId: 'listEsmEscalationChains', summary: 'List escalation chains', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk SLA'], responses: [new OA\Response(response: 200, description: 'Chains with their steps')])]
    public function chains(): JsonResponse
    {
        $this->authorize('viewAny', EscalationChain::class);

        return response()->json(['data' => EscalationChainResource::collection(EscalationChain::query()->where('tenant_id', $this->tenantId())->with('steps')->orderBy('name')->get())]);
    }

    #[OA\Post(path: '/api/v1/esm/helpdesk/escalation-chains', operationId: 'storeEsmEscalationChain', summary: 'Add an escalation chain', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk SLA'], responses: [new OA\Response(response: 201, description: 'Chain added'), new OA\Response(response: 422, description: 'Validation error')])]
    public function storeChain(SaveEscalationChainRequest $request, EscalationChainWriter $writer): JsonResponse
    {
        $this->authorize('create', EscalationChain::class);

        return response()->json(['data' => new EscalationChainResource($writer->create($this->tenantId(), $request->validated()))], 201);
    }

    #[OA\Patch(path: '/api/v1/esm/helpdesk/escalation-chains/{id}', operationId: 'updateEsmEscalationChain', summary: 'Change an escalation chain', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk SLA'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Chain changed'), new OA\Response(response: 404, description: 'Resource not found')])]
    public function updateChain(SaveEscalationChainRequest $request, EscalationChainWriter $writer, string $id): JsonResponse
    {
        $chain = EscalationChain::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $chain);

        return response()->json(['data' => new EscalationChainResource($writer->update($chain, $request->validated()))]);
    }

    #[OA\Delete(path: '/api/v1/esm/helpdesk/escalation-chains/{id}', operationId: 'destroyEsmEscalationChain', summary: 'Delete an escalation chain', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk SLA'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 204, description: 'Chain deleted'), new OA\Response(response: 404, description: 'Resource not found')])]
    public function destroyChain(EscalationChainWriter $writer, string $id): JsonResponse
    {
        $chain = EscalationChain::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('delete', $chain);

        $writer->delete($chain);

        return response()->json(null, 204);
    }

    #[OA\Put(path: '/api/v1/esm/helpdesk/sla-policies/{id}/settings', operationId: 'updateEsmSlaPolicySettings', summary: 'Change the escalation chain, next-update interval and on-hold pause of an SLA policy', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk SLA'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Policy settings changed'), new OA\Response(response: 404, description: 'Resource not found')])]
    public function updatePolicySettings(UpdateSlaPolicySettingsRequest $request, string $id): JsonResponse
    {
        $this->authorize('create', EscalationChain::class);

        $policy = SLAPolicy::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $policy->update($request->validated());

        return response()->json(['data' => [
            'id' => $policy->id,
            'escalation_chain_id' => $policy->escalation_chain_id,
            'next_update_interval_minutes' => $policy->next_update_interval_minutes,
            'pause_on_on_hold' => (bool) $policy->pause_on_on_hold,
        ]]);
    }

    #[OA\Get(path: '/api/v1/esm/helpdesk/holidays', operationId: 'previewEsmHolidays', summary: 'Preview the public holidays of a Swiss canton or Italian region', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk SLA'], responses: [new OA\Response(response: 200, description: 'Holiday dates'), new OA\Response(response: 422, description: 'Validation error')])]
    public function holidays(HolidayPreviewRequest $request, HolidayCatalog $catalog): JsonResponse
    {
        $this->authorize('viewAny', BusinessHoursCalendar::class);

        $year = (int) ($request->validated('year') ?? now()->year);

        return response()->json(['data' => [
            'country' => strtoupper((string) $request->validated('country')),
            'region' => $request->validated('region') === null ? null : strtoupper((string) $request->validated('region')),
            'year' => $year,
            'dates' => $catalog->forYear((string) $request->validated('country'), $request->validated('region'), $year),
        ]]);
    }

    #[OA\Get(path: '/api/v1/esm/helpdesk/breach-risk', operationId: 'listEsmSlaBreachRisk', summary: 'Tickets close to or past an SLA target', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk SLA'], responses: [new OA\Response(response: 200, description: 'The queue, most urgent first')])]
    public function breachRisk(HelpdeskInsightsRequest $request, HelpdeskInsightsService $insights): JsonResponse
    {
        $this->authorize('viewAny', Ticket::class);

        return response()->json(['data' => $insights->breachRiskQueue(
            $this->tenantId(),
            (int) ($request->validated('risk_percent') ?? HelpdeskSetting::current($this->tenantId())->sla_warning_percent),
            (int) ($request->validated('limit') ?? 100),
        )]);
    }

    #[OA\Get(path: '/api/v1/esm/helpdesk/metrics', operationId: 'getEsmCsatAndMttrMetrics', summary: 'CSAT, MTTR and SLA compliance by queue, priority or contract', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk SLA'], responses: [new OA\Response(response: 200, description: 'Metrics'), new OA\Response(response: 403, description: 'Forbidden')])]
    public function metrics(HelpdeskInsightsRequest $request, HelpdeskInsightsService $insights): JsonResponse
    {
        $this->authorize('viewReports', HelpdeskSetting::class);

        $to = CarbonImmutable::parse($request->validated('to') ?? now()->toDateString())->endOfDay();
        $from = CarbonImmutable::parse($request->validated('from') ?? $to->subDays(29)->toDateString())->startOfDay();

        return response()->json(['data' => $insights->csatAndMttr($this->tenantId(), $from, $to, (string) ($request->validated('group_by') ?? 'queue'))]);
    }

    #[OA\Get(path: '/api/v1/esm/tickets/{ticket}/customer-context', operationId: 'getEsmTicketCustomerContext', summary: '360 degree context of the customer of a ticket', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk SLA'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath')], responses: [new OA\Response(response: 200, description: 'Contracts, assets, open invoices and recent tickets')])]
    public function customerContext(Ticket $ticket, HelpdeskInsightsService $insights): JsonResponse
    {
        $this->authorize('view', $ticket);

        return response()->json(['data' => $insights->customerContext($ticket)]);
    }

    #[OA\Get(path: '/api/v1/esm/tickets/{ticket}/escalations', operationId: 'listEsmTicketEscalations', summary: 'Escalation steps fired on a ticket', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk SLA'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath')], responses: [new OA\Response(response: 200, description: 'Immutable escalation audit, oldest first')])]
    public function escalations(Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        return response()->json(['data' => TicketEscalationLogResource::collection(TicketEscalationLog::query()->where('ticket_id', $ticket->id)->orderBy('fired_at')->orderBy('threshold_percent')->get())]);
    }

    #[OA\Get(path: '/api/v1/esm/tickets/{ticket}/time-entries', operationId: 'listEsmTicketTimeEntries', summary: 'Time logged on a ticket', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk SLA'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath')], responses: [new OA\Response(response: 200, description: 'Time entries with their contract or billing outcome')])]
    public function timeEntries(Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        return response()->json(['data' => TicketTimeEntryResource::collection(TicketTimeEntry::query()->where('ticket_id', $ticket->id)->orderBy('created_at')->get())]);
    }

    #[OA\Post(path: '/api/v1/esm/tickets/{ticket}/time-entries', operationId: 'storeEsmTicketTimeEntry', summary: 'Log time on a ticket', description: 'Deducts the time from the prepaid hour bank of the covering service contract; what the bank cannot cover stays billable time and materials.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk SLA'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath')], responses: [new OA\Response(response: 201, description: 'Time logged'), new OA\Response(response: 422, description: 'Validation error')])]
    public function logTime(LogTicketTimeRequest $request, Ticket $ticket, TicketTimeLogger $logger): JsonResponse
    {
        $this->authorize('reply', $ticket);

        $entry = $logger->log(
            $ticket,
            (int) $request->validated('minutes'),
            $request->validated('technician_id'),
            $request->validated('description'),
            (bool) $request->validated('is_billable', true),
            $request->user()?->id,
        );

        return response()->json(['data' => new TicketTimeEntryResource($entry)], 201);
    }

    #[OA\Post(path: '/api/v1/esm/tickets/{ticket}/escalate/field-service', operationId: 'escalateEsmTicketToFieldService', summary: 'Hand a ticket off to a field service work order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk SLA'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath')], responses: [new OA\Response(response: 201, description: 'Work order created, or the one created before'), new OA\Response(response: 422, description: 'The ticket cannot be handed off')])]
    public function toFieldService(HandoffToFieldServiceRequest $request, Ticket $ticket, TicketHandoffService $handoff): JsonResponse
    {
        $this->authorize('escalate', $ticket);

        $workOrder = $handoff->toFieldService($ticket, $request->validated(), $request->user()?->id);

        return response()->json(['data' => new WorkOrderResource($workOrder)], $workOrder->wasRecentlyCreated ? 201 : 200);
    }

    #[OA\Post(path: '/api/v1/esm/tickets/{ticket}/escalate/rma', operationId: 'escalateEsmTicketToRma', summary: 'Hand a ticket off to an RMA return', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk SLA'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath')], responses: [new OA\Response(response: 201, description: 'RMA issued, or the one issued before'), new OA\Response(response: 422, description: 'The ticket cannot be handed off')])]
    public function toRma(HandoffToRmaRequest $request, Ticket $ticket, TicketHandoffService $handoff): JsonResponse
    {
        $this->authorize('escalate', $ticket);

        $result = $handoff->toRma($ticket, $request->validated(), $request->user()?->id);

        return response()->json([
            'data' => new RmaOrderResource($result['rma']),
            'meta' => ['tracking_token' => $result['tracking_token']],
        ], $result['created'] ? 201 : 200);
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }
}
