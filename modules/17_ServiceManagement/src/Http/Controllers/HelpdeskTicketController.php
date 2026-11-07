<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ServiceManagement\Enums\TicketStatus;
use Modules\ServiceManagement\Http\Requests\AddTicketWatcherRequest;
use Modules\ServiceManagement\Http\Requests\ChangeTicketStatusRequest;
use Modules\ServiceManagement\Http\Requests\MergeTicketRequest;
use Modules\ServiceManagement\Http\Requests\SplitTicketRequest;
use Modules\ServiceManagement\Http\Requests\UpdateHelpdeskTicketRequest;
use Modules\ServiceManagement\Http\Resources\HelpdeskTicketActivityResource;
use Modules\ServiceManagement\Http\Resources\HelpdeskTicketWatcherResource;
use Modules\ServiceManagement\Http\Resources\TicketResource;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketActivity;
use Modules\ServiceManagement\Models\TicketWatcher;
use Modules\ServiceManagement\Services\Helpdesk\HelpdeskTicketService;
use Modules\ServiceManagement\Services\Helpdesk\TicketPriorityMatrix;
use OpenApi\Attributes as OA;

/**
 * Helpdesk lifecycle of a ticket: update, status transitions, reopen, merge, split, watchers and history.
 */
final class HelpdeskTicketController extends Controller
{
    public function __construct(private readonly HelpdeskTicketService $tickets) {}

    /**
     * Changes the fields of a ticket. Impact and urgency set the priority through the matrix unless a priority is given.
     */
    #[OA\Patch(path: '/api/v1/esm/tickets/{ticket}', operationId: 'updateEsmTicket', description: 'Changes the fields of a ticket. Impact and urgency set the priority through the priority matrix unless a priority is given; tags replace the list; team and agent reassign the ticket.', summary: 'Update ticket', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Tickets'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskUpdateTicketRequest')), responses: [
        new OA\Response(response: 200, description: 'Ticket updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementTicketResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function update(UpdateHelpdeskTicketRequest $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('update', $ticket);

        return response()->json(['data' => new TicketResource($this->tickets->update($ticket, $request->validated(), $request->user()?->id))]);
    }

    /**
     * Moves a ticket to another status along the allowed transitions.
     */
    #[OA\Post(path: '/api/v1/esm/tickets/{ticket}/status', operationId: 'changeEsmTicketStatus', description: 'Moves a ticket to another status. Allowed: new, open, pending_customer, on_hold, resolved, closed along the transition rules; moving a resolved or closed ticket back to open is a reopen, allowed within the reopen window. Pending customer stops the SLA clock.', summary: 'Change ticket status', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Tickets'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskChangeTicketStatusRequest')), responses: [
        new OA\Response(response: 200, description: 'Status changed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementTicketResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Transition not allowed or validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function changeStatus(ChangeTicketStatusRequest $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('update', $ticket);

        $updated = $this->tickets->changeStatus($ticket, TicketStatus::from($request->validated('status')), $request->user()?->id, $request->validated('note'));

        return response()->json(['data' => new TicketResource($updated)]);
    }

    /**
     * Reopens a resolved or closed ticket within the reopen window.
     */
    #[OA\Post(path: '/api/v1/esm/tickets/{ticket}/reopen', operationId: 'reopenEsmTicket', description: 'Reopens a resolved or closed ticket, within the reopen window of the tenant.', summary: 'Reopen ticket', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Tickets'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath')], responses: [
        new OA\Response(response: 200, description: 'Ticket reopened', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementTicketResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Outside the reopen window or not resolved', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function reopen(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('update', $ticket);

        return response()->json(['data' => new TicketResource($this->tickets->changeStatus($ticket, TicketStatus::Open, $request->user()?->id, 'Reopened'))]);
    }

    /**
     * Merges the ticket into another one.
     */
    #[OA\Post(path: '/api/v1/esm/tickets/{ticket}/merge', operationId: 'mergeEsmTicket', description: 'Merges the ticket into the target ticket. The merged ticket is closed and keeps its own history, which is also shown in the thread of the target; watchers and tags move to the target.', summary: 'Merge ticket', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Tickets'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskMergeTicketRequest')), responses: [
        new OA\Response(response: 200, description: 'Target ticket after the merge', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementTicketResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Merge not allowed or validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function merge(MergeTicketRequest $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('merge', $ticket);
        $target = Ticket::query()->findOrFail($request->validated('target_ticket_id'));
        $this->authorize('merge', $target);

        return response()->json(['data' => new TicketResource($this->tickets->merge($ticket, $target, $request->user()?->id))]);
    }

    /**
     * Moves messages of the ticket into a new ticket.
     */
    #[OA\Post(path: '/api/v1/esm/tickets/{ticket}/split', operationId: 'splitEsmTicket', description: 'Creates a new ticket for the same requester and moves the given messages of the ticket to it.', summary: 'Split ticket', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Tickets'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskSplitTicketRequest')), responses: [
        new OA\Response(response: 201, description: 'New ticket', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementTicketResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Split not allowed or validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function split(SplitTicketRequest $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('merge', $ticket);
        $validated = $request->validated();

        return response()->json(['data' => new TicketResource($this->tickets->split($ticket, $validated['subject'], array_values($validated['message_ids']), $request->user()?->id))], 201);
    }

    /**
     * Lists the watchers of a ticket.
     */
    #[OA\Get(path: '/api/v1/esm/tickets/{ticket}/watchers', operationId: 'listEsmTicketWatchers', description: 'Lists the colleagues who follow the ticket.', summary: 'List ticket watchers', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Tickets'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath')], responses: [
        new OA\Response(response: 200, description: 'Watchers', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskTicketWatcherResource'))])),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function watchers(Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        return response()->json(['data' => HelpdeskTicketWatcherResource::collection(TicketWatcher::query()->with('user')->where('ticket_id', $ticket->id)->orderBy('created_at')->get())]);
    }

    /**
     * Adds a watcher to a ticket.
     */
    #[OA\Post(path: '/api/v1/esm/tickets/{ticket}/watchers', operationId: 'addEsmTicketWatcher', description: 'Adds a colleague of the tenant as watcher of the ticket.', summary: 'Add ticket watcher', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Tickets'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskAddTicketWatcherRequest')), responses: [
        new OA\Response(response: 201, description: 'Watcher added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskTicketWatcherResource')])),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function addWatcher(AddTicketWatcherRequest $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('manageWatchers', $ticket);

        return response()->json(['data' => new HelpdeskTicketWatcherResource($this->tickets->addWatcher($ticket, $request->validated('user_id'), $request->user()?->id))], 201);
    }

    /**
     * Removes a watcher from a ticket.
     */
    #[OA\Delete(path: '/api/v1/esm/tickets/{ticket}/watchers/{user}', operationId: 'removeEsmTicketWatcher', description: 'Removes a colleague from the watchers of the ticket.', summary: 'Remove ticket watcher', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Tickets'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath'), new OA\Parameter(ref: '#/components/parameters/HelpdeskUserPath')], responses: [
        new OA\Response(response: 204, description: 'Watcher removed'),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function removeWatcher(Request $request, Ticket $ticket, string $user): JsonResponse
    {
        $this->authorize('manageWatchers', $ticket);
        $this->tickets->removeWatcher($ticket, $user, $request->user()?->id);

        return response()->json(null, 204);
    }

    /**
     * Returns the history of a ticket.
     */
    #[OA\Get(path: '/api/v1/esm/tickets/{ticket}/activities', operationId: 'listEsmTicketActivities', description: 'Returns the history of the ticket: status changes, assignments, merges, splits and SLA events, oldest first.', summary: 'Ticket history', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Tickets'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath')], responses: [
        new OA\Response(response: 200, description: 'History', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskTicketActivityResource'))])),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function activities(Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        return response()->json(['data' => HelpdeskTicketActivityResource::collection(TicketActivity::query()->where('ticket_id', $ticket->id)->orderBy('created_at')->orderBy('id')->get())]);
    }

    /**
     * Returns the impact and urgency matrix used for priorities.
     */
    #[OA\Get(path: '/api/v1/esm/helpdesk/priority-matrix', operationId: 'getEsmHelpdeskPriorityMatrix', description: 'Returns the priority given by each combination of impact (outer key) and urgency (inner key).', summary: 'Priority matrix', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Tickets'], responses: [
        new OA\Response(response: 200, description: 'Matrix', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskPriorityMatrix')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function priorityMatrix(TicketPriorityMatrix $matrix): JsonResponse
    {
        return response()->json(['data' => ['levels' => TicketPriorityMatrix::Levels, 'matrix' => $matrix->matrix()]]);
    }
}
