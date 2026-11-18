<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Contracts\LeadScoringServiceInterface;
use Modules\CRM\Http\Requests\PreviewLeadScoreRequest;
use Modules\CRM\Http\Requests\StoreLeadScoringRuleRequest;
use Modules\CRM\Http\Resources\LeadScoringRuleResource;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadScoringRule;
use OpenApi\Attributes as OA;

final class LeadScoringRuleController extends Controller
{
    private const array FIELDS = [
        'code', 'name', 'category', 'field', 'event_type', 'operator', 'value', 'points',
        'max_applications', 'decay_days', 'is_active',
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LeadScoringServiceInterface $scoring,
    ) {}

    #[OA\Get(
        path: '/api/v1/crm/scoring-rules',
        operationId: 'listCrmScoringRules',
        summary: 'List lead scoring rules',
        tags: ['CRM'],
        responses: [new OA\Response(response: 200, description: 'Scoring rules')]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeadScoringRule::class);

        $query = LeadScoringRule::query()->where('tenant_id', $this->tenantId());

        if ($request->filled('category')) {
            $query->where('category', (string) $request->query('category'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $rules = $query->orderBy('category')->orderBy('code')->paginate(50);

        return response()->json([
            'data' => LeadScoringRuleResource::collection($rules->getCollection()),
            'meta' => [
                'current_page' => $rules->currentPage(),
                'total' => $rules->total(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/crm/scoring-rules',
        operationId: 'createCrmScoringRule',
        summary: 'Create lead scoring rule',
        tags: ['CRM'],
        responses: [new OA\Response(response: 201, description: 'Scoring rule created')]
    )]
    public function store(StoreLeadScoringRuleRequest $request): JsonResponse
    {
        $this->authorize('create', LeadScoringRule::class);

        $rule = LeadScoringRule::create(array_merge(
            ['is_active' => true],
            $request->safe()->only(self::FIELDS),
            ['tenant_id' => $this->tenantId()],
        ));

        return (new LeadScoringRuleResource($rule))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/crm/scoring-rules/{id}',
        operationId: 'showCrmScoringRule',
        summary: 'Show lead scoring rule',
        tags: ['CRM'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Scoring rule')]
    )]
    public function show(string $id): JsonResponse
    {
        $rule = $this->findRule($id);
        $this->authorize('view', $rule);

        return (new LeadScoringRuleResource($rule))->response();
    }

    #[OA\Put(
        path: '/api/v1/crm/scoring-rules/{id}',
        operationId: 'updateCrmScoringRule',
        summary: 'Update lead scoring rule',
        tags: ['CRM'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Scoring rule updated')]
    )]
    public function update(StoreLeadScoringRuleRequest $request, string $id): JsonResponse
    {
        $rule = $this->findRule($id);
        $this->authorize('update', $rule);

        $rule->update($request->safe()->only(self::FIELDS));

        return (new LeadScoringRuleResource($rule->fresh() ?? $rule))->response();
    }

    #[OA\Delete(
        path: '/api/v1/crm/scoring-rules/{id}',
        operationId: 'deleteCrmScoringRule',
        summary: 'Delete lead scoring rule',
        tags: ['CRM'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 204, description: 'Scoring rule deleted')]
    )]
    public function destroy(string $id): JsonResponse
    {
        $rule = $this->findRule($id);
        $this->authorize('delete', $rule);

        $rule->delete();

        return response()->json(null, 204);
    }

    /**
     * Scores an existing lead (lead_id) or a hypothetical one (lead: {...}) without saving anything.
     */
    #[OA\Post(
        path: '/api/v1/crm/scoring-rules/preview',
        operationId: 'previewCrmScoringRule',
        summary: 'Preview lead score',
        tags: ['CRM'],
        responses: [new OA\Response(response: 200, description: 'Score preview')]
    )]
    public function preview(PreviewLeadScoreRequest $request): JsonResponse
    {
        $this->authorize('viewAny', LeadScoringRule::class);
        $tenantId = $this->tenantId();

        if ($request->filled('lead_id')) {
            $lead = Lead::query()->where('tenant_id', $tenantId)->findOrFail((string) $request->input('lead_id'));
        } else {
            /** @var array<string, mixed> $attributes */
            $attributes = (array) $request->validated('lead', []);
            $lead = new Lead($attributes);
            $lead->tenant_id = $tenantId;
        }

        $result = $this->scoring->evaluate($lead);

        return response()->json([
            'score' => $result->score,
            'rating' => $result->rating,
            'breakdown' => $result->breakdown,
        ]);
    }

    private function findRule(string $id): LeadScoringRule
    {
        return LeadScoringRule::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }
}
