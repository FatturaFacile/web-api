<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Http\Requests\SaveAgentToolRequest;
use Modules\ServiceManagement\Http\Resources\CannedResponseResource;
use Modules\ServiceManagement\Http\Resources\MacroResource;
use Modules\ServiceManagement\Http\Resources\SavedViewResource;
use Modules\ServiceManagement\Http\Resources\TicketResource;
use Modules\ServiceManagement\Models\CannedResponse;
use Modules\ServiceManagement\Models\Macro;
use Modules\ServiceManagement\Models\SavedView;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Services\Helpdesk\HelpdeskMacroService;
use Modules\ServiceManagement\Services\Helpdesk\TicketPlaceholderRenderer;
use OpenApi\Attributes as OA;

/**
 * Canned responses, macros and saved views of the agents. Each is personal or shared; sharing needs a service manager.
 */
final class AgentToolsController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(path: '/api/v1/esm/helpdesk/canned-responses', operationId: 'listEsmCannedResponses', description: 'Lists the shared canned responses and those of the caller.', summary: 'List canned responses', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Agent Tools'], responses: [
        new OA\Response(response: 200, description: 'Canned responses', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskCannedResponseResource'))])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function cannedResponses(Request $request): JsonResponse
    {
        return $this->list($request, CannedResponse::class, CannedResponseResource::class, 'title');
    }

    #[OA\Post(path: '/api/v1/esm/helpdesk/canned-responses', operationId: 'storeEsmCannedResponse', description: 'Adds a canned response. Placeholders: {{ticket.number}}, {{ticket.subject}}, {{ticket.status}}, {{ticket.priority}}, {{ticket.created_at}}, {{requester.name}}, {{requester.email}}, {{agent.name}}, {{agent.email}}, {{company.name}}.', summary: 'Add canned response', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Agent Tools'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskSaveCannedResponseRequest')), responses: [
        new OA\Response(response: 201, description: 'Canned response added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskCannedResponseResource')])),
        new OA\Response(response: 403, description: 'Sharing needs a service manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function storeCannedResponse(SaveAgentToolRequest $request): JsonResponse
    {
        return $this->store($request, CannedResponse::class, CannedResponseResource::class);
    }

    #[OA\Patch(path: '/api/v1/esm/helpdesk/canned-responses/{id}', operationId: 'updateEsmCannedResponse', description: 'Changes a canned response of the caller, or a shared one for a service manager.', summary: 'Change canned response', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Agent Tools'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskSaveCannedResponseRequest')), responses: [
        new OA\Response(response: 200, description: 'Canned response changed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskCannedResponseResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function updateCannedResponse(SaveAgentToolRequest $request, string $id): JsonResponse
    {
        return $this->update($request, CannedResponse::class, CannedResponseResource::class, $id);
    }

    #[OA\Delete(path: '/api/v1/esm/helpdesk/canned-responses/{id}', operationId: 'deleteEsmCannedResponse', description: 'Removes a canned response.', summary: 'Remove canned response', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Agent Tools'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], responses: [
        new OA\Response(response: 204, description: 'Removed'),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function destroyCannedResponse(Request $request, string $id): JsonResponse
    {
        return $this->destroy($request, CannedResponse::class, $id);
    }

    #[OA\Post(path: '/api/v1/esm/tickets/{ticket}/canned-responses/{id}/render', operationId: 'renderEsmCannedResponse', description: 'Returns the text of a canned response with the placeholders filled for the ticket and the caller.', summary: 'Render canned response', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Agent Tools'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath'), new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], responses: [
        new OA\Response(response: 200, description: 'Rendered text', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'body', type: 'string')], type: 'object')])),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function renderCannedResponse(Request $request, Ticket $ticket, string $id, TicketPlaceholderRenderer $renderer): JsonResponse
    {
        $this->authorize('view', $ticket);
        $response = CannedResponse::query()->where('tenant_id', $this->tenantId())->visibleTo($request->user()->id)->findOrFail($id);

        return response()->json(['data' => ['body' => $renderer->render($response->body, $ticket, $request->user())]]);
    }

    #[OA\Get(path: '/api/v1/esm/helpdesk/macros', operationId: 'listEsmMacros', description: 'Lists the shared macros and those of the caller.', summary: 'List macros', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Agent Tools'], responses: [
        new OA\Response(response: 200, description: 'Macros', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskMacroResource'))])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function macros(Request $request): JsonResponse
    {
        return $this->list($request, Macro::class, MacroResource::class, 'name');
    }

    #[OA\Post(path: '/api/v1/esm/helpdesk/macros', operationId: 'storeEsmMacro', description: 'Adds a macro: status, priority, assignment and tag changes plus an optional reply with placeholders, applied in one action.', summary: 'Add macro', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Agent Tools'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskSaveMacroRequest')), responses: [
        new OA\Response(response: 201, description: 'Macro added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskMacroResource')])),
        new OA\Response(response: 403, description: 'Sharing needs a service manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function storeMacro(SaveAgentToolRequest $request): JsonResponse
    {
        return $this->store($request, Macro::class, MacroResource::class);
    }

    #[OA\Patch(path: '/api/v1/esm/helpdesk/macros/{id}', operationId: 'updateEsmMacro', description: 'Changes a macro of the caller, or a shared one for a service manager.', summary: 'Change macro', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Agent Tools'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskSaveMacroRequest')), responses: [
        new OA\Response(response: 200, description: 'Macro changed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskMacroResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function updateMacro(SaveAgentToolRequest $request, string $id): JsonResponse
    {
        return $this->update($request, Macro::class, MacroResource::class, $id);
    }

    #[OA\Delete(path: '/api/v1/esm/helpdesk/macros/{id}', operationId: 'deleteEsmMacro', description: 'Removes a macro.', summary: 'Remove macro', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Agent Tools'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], responses: [
        new OA\Response(response: 204, description: 'Removed'),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function destroyMacro(Request $request, string $id): JsonResponse
    {
        return $this->destroy($request, Macro::class, $id);
    }

    #[OA\Post(path: '/api/v1/esm/tickets/{ticket}/macros/{id}/apply', operationId: 'applyEsmMacro', description: 'Applies a macro to the ticket in one transaction; if a step is refused nothing changes.', summary: 'Apply macro', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Agent Tools'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath'), new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], responses: [
        new OA\Response(response: 200, description: 'Ticket after the macro', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementTicketResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'A step of the macro was refused', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function applyMacro(Request $request, Ticket $ticket, string $id, HelpdeskMacroService $macros): JsonResponse
    {
        $this->authorize('update', $ticket);
        $macro = Macro::query()->where('tenant_id', $this->tenantId())->visibleTo($request->user()->id)->findOrFail($id);

        return response()->json(['data' => new TicketResource($macros->apply($macro, $ticket, $request->user()))]);
    }

    #[OA\Get(path: '/api/v1/esm/helpdesk/views', operationId: 'listEsmSavedViews', description: 'Lists the shared ticket views and those of the caller. Pass view_id to the ticket list to apply one.', summary: 'List saved views', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Agent Tools'], responses: [
        new OA\Response(response: 200, description: 'Views', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskSavedViewResource'))])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function views(Request $request): JsonResponse
    {
        return $this->list($request, SavedView::class, SavedViewResource::class, 'position');
    }

    #[OA\Post(path: '/api/v1/esm/helpdesk/views', operationId: 'storeEsmSavedView', description: 'Saves a set of ticket list filters (status, priority, team_id, agent_id, unassigned, tag, sla_state, customer_id, q, source, include_merged).', summary: 'Add saved view', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Agent Tools'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskSaveSavedViewRequest')), responses: [
        new OA\Response(response: 201, description: 'View added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskSavedViewResource')])),
        new OA\Response(response: 403, description: 'Sharing needs a service manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function storeView(SaveAgentToolRequest $request): JsonResponse
    {
        return $this->store($request, SavedView::class, SavedViewResource::class);
    }

    #[OA\Patch(path: '/api/v1/esm/helpdesk/views/{id}', operationId: 'updateEsmSavedView', description: 'Changes a saved view of the caller, or a shared one for a service manager.', summary: 'Change saved view', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Agent Tools'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskSaveSavedViewRequest')), responses: [
        new OA\Response(response: 200, description: 'View changed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskSavedViewResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function updateView(SaveAgentToolRequest $request, string $id): JsonResponse
    {
        return $this->update($request, SavedView::class, SavedViewResource::class, $id);
    }

    #[OA\Delete(path: '/api/v1/esm/helpdesk/views/{id}', operationId: 'deleteEsmSavedView', description: 'Removes a saved view.', summary: 'Remove saved view', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Agent Tools'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], responses: [
        new OA\Response(response: 204, description: 'Removed'),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function destroyView(Request $request, string $id): JsonResponse
    {
        return $this->destroy($request, SavedView::class, $id);
    }

    /**
     * @param  class-string<CannedResponse|Macro|SavedView>  $model
     * @param  class-string<JsonResource>  $resource
     */
    private function list(Request $request, string $model, string $resource, string $orderBy): JsonResponse
    {
        $this->authorize('viewAny', $model);

        return response()->json(['data' => $resource::collection($model::query()->where('tenant_id', $this->tenantId())->visibleTo($request->user()->id)->orderBy($orderBy)->get())]);
    }

    /**
     * @param  class-string<CannedResponse|Macro|SavedView>  $model
     * @param  class-string<JsonResource>  $resource
     */
    private function store(SaveAgentToolRequest $request, string $model, string $resource): JsonResponse
    {
        $this->authorize('create', $model);
        $validated = $request->validated();

        if ((bool) ($validated['is_shared'] ?? false)) {
            $this->authorize('share', $model);
        }

        $tool = $model::query()->create([...$validated, 'tenant_id' => $this->tenantId(), 'owner_user_id' => $request->user()->id]);

        return response()->json(['data' => new $resource($tool)], 201);
    }

    /**
     * @param  class-string<CannedResponse|Macro|SavedView>  $model
     * @param  class-string<JsonResource>  $resource
     */
    private function update(SaveAgentToolRequest $request, string $model, string $resource, string $id): JsonResponse
    {
        $tool = $this->find($request, $model, $id);
        $this->authorize('update', $tool);
        $validated = $request->validated();

        if (array_key_exists('is_shared', $validated) && (bool) $validated['is_shared'] !== (bool) $tool->is_shared) {
            $this->authorize('share', $model);
        }

        $tool->update($validated);

        return response()->json(['data' => new $resource($tool->refresh())]);
    }

    /**
     * @param  class-string<CannedResponse|Macro|SavedView>  $model
     */
    private function destroy(Request $request, string $model, string $id): JsonResponse
    {
        $tool = $this->find($request, $model, $id);
        $this->authorize('delete', $tool);
        $tool->delete();

        return response()->json(null, 204);
    }

    /**
     * @param  class-string<CannedResponse|Macro|SavedView>  $model
     */
    private function find(Request $request, string $model, string $id): Model
    {
        return $model::query()->where('tenant_id', $this->tenantId())->visibleTo($request->user()->id)->findOrFail($id);
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }
}
