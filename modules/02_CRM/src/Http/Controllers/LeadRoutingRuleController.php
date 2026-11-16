<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\CRM\Contracts\LeadRoutingServiceInterface;
use Modules\CRM\Http\Requests\ReorderLeadRoutingRulesRequest;
use Modules\CRM\Http\Requests\StoreLeadRoutingRuleRequest;
use Modules\CRM\Http\Resources\LeadRoutingRuleResource;
use Modules\CRM\Models\LeadRoutingRule;

final class LeadRoutingRuleController extends Controller
{
    private const array FIELDS = ['name', 'priority', 'strategy', 'assignee_user_ids', 'is_default', 'is_active'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LeadRoutingServiceInterface $routing,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', LeadRoutingRule::class);

        $rules = LeadRoutingRule::query()
            ->where('tenant_id', $this->tenantId())
            ->orderBy('priority')
            ->orderBy('created_at')
            ->paginate(100);

        return response()->json([
            'data' => LeadRoutingRuleResource::collection($rules->getCollection()),
            'meta' => [
                'current_page' => $rules->currentPage(),
                'total' => $rules->total(),
            ],
        ]);
    }

    public function store(StoreLeadRoutingRuleRequest $request): JsonResponse
    {
        $this->authorize('create', LeadRoutingRule::class);

        $data = $request->safe()->only(self::FIELDS);
        $data['conditions'] = $request->conditions();
        $data['assignee_user_ids'] = array_values((array) ($data['assignee_user_ids'] ?? []));

        $rule = LeadRoutingRule::create(array_merge(
            ['priority' => 100, 'is_default' => false, 'is_active' => true, 'round_robin_cursor' => 0],
            $data,
            ['tenant_id' => $this->tenantId()],
        ));

        return (new LeadRoutingRuleResource($rule))->response()->setStatusCode(201);
    }

    public function show(string $id): JsonResponse
    {
        $rule = $this->findRule($id);
        $this->authorize('view', $rule);

        return (new LeadRoutingRuleResource($rule))->response();
    }

    public function update(StoreLeadRoutingRuleRequest $request, string $id): JsonResponse
    {
        $rule = $this->findRule($id);
        $this->authorize('update', $rule);

        $data = $request->safe()->only(self::FIELDS);
        if ($request->has('conditions')) {
            $data['conditions'] = $request->conditions();
        }
        if (array_key_exists('assignee_user_ids', $data)) {
            $data['assignee_user_ids'] = array_values((array) $data['assignee_user_ids']);
            // A different rep list restarts the rotation from the first rep.
            $data['round_robin_cursor'] = 0;
        }

        $rule->forceFill($data)->save();

        return (new LeadRoutingRuleResource($rule))->response();
    }

    public function destroy(string $id): JsonResponse
    {
        $rule = $this->findRule($id);
        $this->authorize('delete', $rule);

        $rule->delete();

        return response()->json(null, 204);
    }

    public function reorder(ReorderLeadRoutingRulesRequest $request): JsonResponse
    {
        $this->authorize('create', LeadRoutingRule::class);

        /** @var list<string> $ids */
        $ids = array_values(array_map('strval', (array) $request->validated('ids')));

        $known = LeadRoutingRule::query()->where('tenant_id', $this->tenantId())->whereIn('id', $ids)->count();
        if ($known !== count($ids)) {
            return response()->json([
                'message' => 'One or more routing rules do not exist.',
                'errors' => ['ids' => ['One or more routing rules do not exist.']],
            ], 422);
        }

        $this->routing->reorder($this->tenantId(), $ids);

        return $this->index();
    }

    private function findRule(string $id): LeadRoutingRule
    {
        return LeadRoutingRule::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }
}
