<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyTriggerEvent;
use Modules\CustomerSatisfaction\Http\Controllers\Concerns\InteractsWithSatisfaction;
use Modules\CustomerSatisfaction\Http\Requests\ListFiltersRequest;
use Modules\CustomerSatisfaction\Http\Requests\NoInputRequest;
use Modules\CustomerSatisfaction\Http\Requests\TriggerSurveyRequest;
use Modules\CustomerSatisfaction\Http\Resources\SurveyDispatchResource;
use Modules\CustomerSatisfaction\Models\SurveyDispatch;
use Modules\CustomerSatisfaction\Services\SurveyDispatchProcessor;
use Modules\CustomerSatisfaction\Services\SurveyScheduler;
use Modules\CustomerSatisfaction\Services\SurveyTriggerContext;
use OpenApi\Attributes as OA;

/**
 * The dispatch monitor: every survey invitation with its status and throttle reason, the intake of ERP events and the manual run.
 */
final class DispatchController extends Controller
{
    use ApiResponse;
    use InteractsWithSatisfaction;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/customer-satisfaction/dispatches', operationId: 'customerSatisfactionListDispatches', summary: 'List the survey dispatches', security: self::SECURITY, tags: ['Customer Satisfaction'], responses: [new OA\Response(response: 200, description: 'Dispatches, paginated')])]
    public function index(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', SurveyDispatch::class);

        $page = SurveyDispatch::query()
            ->with(['template', 'customer'])
            ->when($request->input('dispatch_status'), fn ($rows, $status) => $rows->where('status', $status))
            ->when($request->input('trigger_event'), fn ($rows, $event) => $rows->where('trigger_event', $event))
            ->when($request->input('customer_id'), fn ($rows, $customer) => $rows->where('customer_id', $customer))
            ->when($request->input('survey_type'), fn ($rows, $type) => $rows->whereHas('template', fn ($template) => $template->where('survey_type', $type)))
            ->when($request->input('from'), fn ($rows, $from) => $rows->where('created_at', '>=', $from))
            ->when($request->input('to'), fn ($rows, $to) => $rows->where('created_at', '<=', $to.' 23:59:59'))
            ->latest('created_at')
            ->paginate($this->perPage());

        return $this->paged($page, SurveyDispatchResource::class);
    }

    #[OA\Get(path: '/api/v1/customer-satisfaction/dispatches/{id}', operationId: 'customerSatisfactionShowDispatch', summary: 'Show a survey dispatch with its answer', security: self::SECURITY, tags: ['Customer Satisfaction'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Dispatch')])]
    public function show(string $id): JsonResponse
    {
        $dispatch = SurveyDispatch::query()->with(['template', 'customer', 'response'])->findOrFail($id);
        Gate::authorize('view', $dispatch);

        return $this->successResponse(new SurveyDispatchResource($dispatch), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/customer-satisfaction/triggers', operationId: 'customerSatisfactionTriggerSurvey', summary: 'Report an ERP event (order delivered, onboarding completed, ...) that may trigger a survey', security: self::SECURITY, tags: ['Customer Satisfaction'], responses: [new OA\Response(response: 200, description: 'The dispatch created, throttled or suppressed; null when no rule matches')])]
    public function trigger(TriggerSurveyRequest $request, SurveyScheduler $scheduler): JsonResponse
    {
        Gate::authorize('create', SurveyDispatch::class);
        $data = $request->validated();

        $dispatch = $scheduler->handle(new SurveyTriggerContext(
            SurveyTriggerEvent::from($data['event']),
            $data['customer_id'],
            $data['source_entity_type'] ?? null,
            $data['source_entity_id'] ?? null,
            $data['locale'] ?? null,
            $data['dimensions'] ?? [],
        ), $this->tenantId());

        return $this->successResponse($dispatch === null ? null : new SurveyDispatchResource($dispatch->load(['template', 'customer'])), $this->message($dispatch === null ? 'no_matching_rule' : 'dispatch_recorded'));
    }

    #[OA\Post(path: '/api/v1/customer-satisfaction/dispatches/process', operationId: 'customerSatisfactionProcessDispatches', summary: 'Send the due invitations now (the scheduler does it every five minutes)', security: self::SECURITY, tags: ['Customer Satisfaction'], responses: [new OA\Response(response: 200, description: 'Sent, failed and suppressed counts')])]
    public function process(NoInputRequest $request, SurveyDispatchProcessor $processor): JsonResponse
    {
        Gate::authorize('create', SurveyDispatch::class);

        return $this->successResponse($processor->processDue(), $this->message('dispatches_processed'));
    }
}
