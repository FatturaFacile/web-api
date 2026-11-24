<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\ServiceManagement\Http\Requests\SaveBusinessHoursCalendarRequest;
use Modules\ServiceManagement\Http\Requests\SaveRoutingRuleRequest;
use Modules\ServiceManagement\Http\Requests\UpdateHelpdeskSettingsRequest;
use Modules\ServiceManagement\Http\Requests\UpdateTeamAssignmentRequest;
use Modules\ServiceManagement\Http\Resources\BusinessHoursCalendarResource;
use Modules\ServiceManagement\Http\Resources\HelpdeskSettingResource;
use Modules\ServiceManagement\Http\Resources\RoutingRuleResource;
use Modules\ServiceManagement\Models\BusinessHoursCalendar;
use Modules\ServiceManagement\Models\HelpdeskSetting;
use Modules\ServiceManagement\Models\RoutingRule;
use Modules\ServiceManagement\Models\ServiceTeam;
use OpenApi\Attributes as OA;

/**
 * Routing rules, business hours, team assignment strategy and helpdesk settings. Every member reads them, the service
 * managers change them.
 */
final class HelpdeskAutomationController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Lists the routing rules by position.
     */
    #[OA\Get(path: '/api/v1/esm/helpdesk/routing-rules', operationId: 'listEsmRoutingRules', description: 'Lists the routing rules of the tenant in the order they run.', summary: 'List routing rules', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Automation'], responses: [
        new OA\Response(response: 200, description: 'Rules', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskRoutingRuleResource'))])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function routingRules(): JsonResponse
    {
        $this->authorize('viewAny', RoutingRule::class);

        return response()->json(['data' => RoutingRuleResource::collection(RoutingRule::query()->where('tenant_id', $this->tenantId())->orderBy('position')->orderBy('id')->get())]);
    }

    /**
     * Adds a routing rule.
     */
    #[OA\Post(path: '/api/v1/esm/helpdesk/routing-rules', operationId: 'storeEsmRoutingRule', description: 'Adds a routing rule. A new ticket matching every condition given gets the actions: team or agent (with round robin or least loaded choice inside the team), priority, tags.', summary: 'Add routing rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Automation'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskSaveRoutingRuleRequest')), responses: [
        new OA\Response(response: 201, description: 'Rule added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskRoutingRuleResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function storeRoutingRule(SaveRoutingRuleRequest $request): JsonResponse
    {
        $this->authorize('create', RoutingRule::class);

        return response()->json(['data' => new RoutingRuleResource(RoutingRule::query()->create([...$request->validated(), 'tenant_id' => $this->tenantId()]))], 201);
    }

    /**
     * Changes a routing rule.
     */
    #[OA\Patch(path: '/api/v1/esm/helpdesk/routing-rules/{id}', operationId: 'updateEsmRoutingRule', description: 'Changes a routing rule.', summary: 'Change routing rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Automation'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskSaveRoutingRuleRequest')), responses: [
        new OA\Response(response: 200, description: 'Rule changed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskRoutingRuleResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function updateRoutingRule(SaveRoutingRuleRequest $request, string $id): JsonResponse
    {
        $rule = RoutingRule::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $rule);
        $rule->update($request->validated());

        return response()->json(['data' => new RoutingRuleResource($rule->refresh())]);
    }

    /**
     * Removes a routing rule.
     */
    #[OA\Delete(path: '/api/v1/esm/helpdesk/routing-rules/{id}', operationId: 'deleteEsmRoutingRule', description: 'Removes a routing rule.', summary: 'Remove routing rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Automation'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], responses: [
        new OA\Response(response: 204, description: 'Rule removed'),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function destroyRoutingRule(string $id): JsonResponse
    {
        $rule = RoutingRule::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('delete', $rule);
        $rule->delete();

        return response()->json(null, 204);
    }

    /**
     * Lists the business hours calendars.
     */
    #[OA\Get(path: '/api/v1/esm/helpdesk/business-hours', operationId: 'listEsmBusinessHours', description: 'Lists the business hours calendars of the tenant.', summary: 'List business hours', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Automation'], responses: [
        new OA\Response(response: 200, description: 'Calendars', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskBusinessHoursCalendarResource'))])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function businessHours(): JsonResponse
    {
        $this->authorize('viewAny', BusinessHoursCalendar::class);

        return response()->json(['data' => BusinessHoursCalendarResource::collection(BusinessHoursCalendar::query()->where('tenant_id', $this->tenantId())->orderBy('name')->get())]);
    }

    /**
     * Adds a business hours calendar.
     */
    #[OA\Post(path: '/api/v1/esm/helpdesk/business-hours', operationId: 'storeEsmBusinessHours', description: 'Adds a business hours calendar: weekly windows in a time zone, holidays, and optionally an HR holiday calendar whose holidays also stop the SLA clock.', summary: 'Add business hours', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Automation'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskSaveBusinessHoursCalendarRequest')), responses: [
        new OA\Response(response: 201, description: 'Calendar added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskBusinessHoursCalendarResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function storeBusinessHours(SaveBusinessHoursCalendarRequest $request): JsonResponse
    {
        $this->authorize('create', BusinessHoursCalendar::class);

        return response()->json(['data' => new BusinessHoursCalendarResource(BusinessHoursCalendar::query()->create([...$request->validated(), 'tenant_id' => $this->tenantId()]))], 201);
    }

    /**
     * Changes a business hours calendar.
     */
    #[OA\Patch(path: '/api/v1/esm/helpdesk/business-hours/{id}', operationId: 'updateEsmBusinessHours', description: 'Changes a business hours calendar. Deadlines already set are not recomputed.', summary: 'Change business hours', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Automation'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskSaveBusinessHoursCalendarRequest')), responses: [
        new OA\Response(response: 200, description: 'Calendar changed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskBusinessHoursCalendarResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function updateBusinessHours(SaveBusinessHoursCalendarRequest $request, string $id): JsonResponse
    {
        $calendar = BusinessHoursCalendar::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $calendar);
        $calendar->update($request->validated());

        return response()->json(['data' => new BusinessHoursCalendarResource($calendar->refresh())]);
    }

    /**
     * Removes a business hours calendar.
     */
    #[OA\Delete(path: '/api/v1/esm/helpdesk/business-hours/{id}', operationId: 'deleteEsmBusinessHours', description: 'Removes a business hours calendar; SLA clocks that used it run around the clock afterwards.', summary: 'Remove business hours', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Automation'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], responses: [
        new OA\Response(response: 204, description: 'Calendar removed'),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function destroyBusinessHours(string $id): JsonResponse
    {
        $calendar = BusinessHoursCalendar::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('delete', $calendar);
        $calendar->delete();

        return response()->json(null, 204);
    }

    /**
     * Returns the helpdesk settings of the tenant.
     */
    #[OA\Get(path: '/api/v1/esm/helpdesk/settings', operationId: 'showEsmHelpdeskSettings', description: 'Returns the helpdesk settings of the tenant, with the installation defaults where nothing was saved.', summary: 'Helpdesk settings', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Automation'], responses: [
        new OA\Response(response: 200, description: 'Settings', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskSettingResource')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function settings(): JsonResponse
    {
        $this->authorize('viewAny', HelpdeskSetting::class);

        return response()->json(['data' => new HelpdeskSettingResource(HelpdeskSetting::current($this->tenantId()))]);
    }

    /**
     * Changes the helpdesk settings of the tenant.
     */
    #[OA\Put(path: '/api/v1/esm/helpdesk/settings', operationId: 'updateEsmHelpdeskSettings', description: 'Changes the reopen window, the SLA warning share, the business hours calendar of the SLA clocks and the satisfaction surveys.', summary: 'Change helpdesk settings', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Automation'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskUpdateSettingsRequest')), responses: [
        new OA\Response(response: 200, description: 'Settings changed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskSettingResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function updateSettings(UpdateHelpdeskSettingsRequest $request): JsonResponse
    {
        $this->authorize('create', HelpdeskSetting::class);

        $settings = HelpdeskSetting::current($this->tenantId());
        $settings->fill($request->validated());
        $settings->tenant_id = $this->tenantId();
        $settings->save();

        return response()->json(['data' => new HelpdeskSettingResource($settings)]);
    }

    /**
     * Sets how a team hands new tickets to its agents.
     */
    #[OA\Put(path: '/api/v1/esm/helpdesk/teams/{id}/assignment', operationId: 'updateEsmTeamAssignment', description: 'Sets the automatic assignment of a team (queue): manual, round robin among available agents, or the available agent with the fewest active tickets.', summary: 'Team assignment strategy', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Automation'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskUpdateTeamAssignmentRequest')), responses: [
        new OA\Response(response: 200, description: 'Strategy set', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'assignment_strategy', type: 'string')], type: 'object')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function updateTeamAssignment(UpdateTeamAssignmentRequest $request, string $id): JsonResponse
    {
        $team = ServiceTeam::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $team);
        $team->assignment_strategy = $request->validated('assignment_strategy');
        $team->save();

        return response()->json(['data' => ['id' => $team->id, 'assignment_strategy' => $team->assignment_strategy]]);
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }
}
