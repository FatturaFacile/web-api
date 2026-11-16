<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Models\LeadRoutingRule;

/**
 * CRUD for the automatic lead assignment rules (geographic, product line, company size, round-robin).
 */
final class LeadRoutingRuleController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => LeadRoutingRule::where('tenant_id', $this->tenantContext->getTenantId())->orderBy('priority')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $rule = LeadRoutingRule::create(['tenant_id' => $this->tenantContext->getTenantId()] + $this->validated($request));

        return response()->json($rule, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $rule = LeadRoutingRule::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
        $rule->update($this->validated($request, false));

        return response()->json($rule->fresh());
    }

    public function destroy(string $id): JsonResponse
    {
        LeadRoutingRule::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:150'],
            'strategy' => [$required, 'in:geographic,product_line,company_size,round_robin'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'criteria' => ['nullable', 'array'],
            'criteria.countries' => ['nullable', 'array'],
            'criteria.regions' => ['nullable', 'array'],
            'criteria.product_lines' => ['nullable', 'array'],
            'criteria.min_size' => ['nullable', 'integer', 'min:0'],
            'criteria.max_size' => ['nullable', 'integer', 'min:0'],
            'assignee_user_ids' => [$required, 'array', 'min:1'],
            'assignee_user_ids.*' => ['uuid'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
