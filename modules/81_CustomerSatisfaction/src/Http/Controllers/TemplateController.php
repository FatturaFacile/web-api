<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\CustomerSatisfaction\Http\Controllers\Concerns\InteractsWithSatisfaction;
use Modules\CustomerSatisfaction\Http\Requests\ListFiltersRequest;
use Modules\CustomerSatisfaction\Http\Requests\NoInputRequest;
use Modules\CustomerSatisfaction\Http\Requests\SaveTemplateRequest;
use Modules\CustomerSatisfaction\Http\Resources\SurveyTemplateResource;
use Modules\CustomerSatisfaction\Models\SurveyTemplate;
use Modules\CustomerSatisfaction\Services\SurveyTemplateDefaults;
use OpenApi\Attributes as OA;

/**
 * Survey templates: the NPS / CSAT / CES instruments with their trigger rule, channels, cooldown, SLA and localized texts.
 */
final class TemplateController extends Controller
{
    use ApiResponse;
    use InteractsWithSatisfaction;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/customer-satisfaction/templates', operationId: 'customerSatisfactionListTemplates', summary: 'List the survey templates and trigger rules', security: self::SECURITY, tags: ['Customer Satisfaction'], responses: [new OA\Response(response: 200, description: 'Templates, paginated')])]
    public function index(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', SurveyTemplate::class);

        $page = SurveyTemplate::query()
            ->when($request->input('q'), fn ($rows, $term) => $rows->where(fn ($inner) => $inner->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower((string) $term).'%'])->orWhereRaw('LOWER(code) LIKE ?', ['%'.mb_strtolower((string) $term).'%'])))
            ->when($request->input('survey_type'), fn ($rows, $type) => $rows->where('survey_type', $type))
            ->when($request->input('trigger_event'), fn ($rows, $event) => $rows->where('trigger_event', $event))
            ->when($request->has('is_active'), fn ($rows) => $rows->where('is_active', $request->boolean('is_active')))
            ->orderBy('trigger_event')
            ->orderBy('priority')
            ->orderBy('code')
            ->paginate($this->perPage(50));

        return $this->paged($page, SurveyTemplateResource::class);
    }

    #[OA\Post(path: '/api/v1/customer-satisfaction/templates', operationId: 'customerSatisfactionCreateTemplate', summary: 'Create a survey template', security: self::SECURITY, tags: ['Customer Satisfaction'], responses: [new OA\Response(response: 201, description: 'Template created')])]
    public function store(SaveTemplateRequest $request): JsonResponse
    {
        Gate::authorize('create', SurveyTemplate::class);

        $template = SurveyTemplate::query()->create([
            'cooldown_days' => (int) config('customer_satisfaction.default_cooldown_days'),
            'sla_hours' => (int) config('customer_satisfaction.default_sla_hours'),
            ...$request->validated(),
            'tenant_id' => $this->tenantId(),
            'created_by' => $this->userId(),
        ]);

        return $this->successResponse(new SurveyTemplateResource($template->refresh()), $this->message('saved'), 201);
    }

    #[OA\Get(path: '/api/v1/customer-satisfaction/templates/{id}', operationId: 'customerSatisfactionShowTemplate', summary: 'Show a survey template', security: self::SECURITY, tags: ['Customer Satisfaction'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Template')])]
    public function show(string $id): JsonResponse
    {
        $template = SurveyTemplate::query()->findOrFail($id);
        Gate::authorize('view', $template);

        return $this->successResponse(new SurveyTemplateResource($template), $this->message('loaded'));
    }

    #[OA\Put(path: '/api/v1/customer-satisfaction/templates/{id}', operationId: 'customerSatisfactionUpdateTemplate', summary: 'Update a survey template', security: self::SECURITY, tags: ['Customer Satisfaction'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Template updated')])]
    public function update(SaveTemplateRequest $request, string $id): JsonResponse
    {
        $template = SurveyTemplate::query()->findOrFail($id);
        Gate::authorize('update', $template);
        $template->update([...$request->validated(), 'updated_by' => $this->userId()]);

        return $this->successResponse(new SurveyTemplateResource($template->refresh()), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/customer-satisfaction/templates/{id}', operationId: 'customerSatisfactionDeleteTemplate', summary: 'Delete a survey template (its dispatches and answers are kept)', security: self::SECURITY, tags: ['Customer Satisfaction'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Template deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $template = SurveyTemplate::query()->findOrFail($id);
        Gate::authorize('delete', $template);
        $template->update(['is_active' => false, 'deleted_by' => $this->userId()]);
        $template->delete();

        return $this->successResponse(null, $this->message('deleted'));
    }

    #[OA\Post(path: '/api/v1/customer-satisfaction/templates/install-defaults', operationId: 'customerSatisfactionInstallDefaultTemplates', summary: 'Install the stock NPS, CSAT and CES templates missing in the company', security: self::SECURITY, tags: ['Customer Satisfaction'], responses: [new OA\Response(response: 200, description: 'Number of templates created')])]
    public function installDefaults(NoInputRequest $request): JsonResponse
    {
        Gate::authorize('create', SurveyTemplate::class);

        return $this->successResponse(['created' => SurveyTemplateDefaults::install()], $this->message('defaults_installed'));
    }
}
