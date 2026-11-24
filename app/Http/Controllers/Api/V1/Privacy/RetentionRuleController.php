<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Privacy;

use App\Http\Requests\Privacy\UpdateRetentionRuleRequest;
use App\Http\Resources\Privacy\RetentionRuleResource;
use App\Http\Resources\Privacy\RetentionRunLogResource;
use App\Models\Privacy\DataRetentionPolicyRule;
use App\Models\Privacy\PrivacyRetentionRunLog;
use App\Services\Privacy\Governance\Commands\RunAutomatedRetentionPurgeCommand;
use App\Services\Privacy\Governance\Commands\RunAutomatedRetentionPurgeHandler;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Retention rules of the tenant and the manual trigger of the purge.
 */
final class RetentionRuleController extends PrivacyGovernanceController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $rules = DataRetentionPolicyRule::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->orderBy('module_code')->get();
        $runs = PrivacyRetentionRunLog::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->orderByDesc('started_at')->limit(10)->get();

        return response()->json([
            'data' => RetentionRuleResource::collection($rules)->resolve($request),
            'recent_runs' => RetentionRunLogResource::collection($runs)->resolve($request),
        ]);
    }

    public function update(UpdateRetentionRuleRequest $request, string $id): JsonResponse
    {
        $rule = DataRetentionPolicyRule::withoutGlobalScope(TenantScope::class)->where('tenant_id', $this->tenantId($request))->whereKey($id)->firstOrFail();
        if ($rule->is_statutory_locked && $request->hasAny(['retention_days', 'action_on_expiry'])) {
            return response()->json(['message' => 'The retention of a statutory locked module is fixed by law and cannot be changed.'], 422);
        }

        $rule->update($request->validated());

        return (new RetentionRuleResource($rule->refresh()))->response();
    }

    public function runNow(Request $request, RunAutomatedRetentionPurgeHandler $handler): JsonResponse
    {
        $log = $handler->handle(new RunAutomatedRetentionPurgeCommand($this->tenantId($request), $request->boolean('dry_run')));

        return (new RetentionRunLogResource($log->refresh()))->response()->setStatusCode(201);
    }

    public function runs(Request $request): AnonymousResourceCollection
    {
        return RetentionRunLogResource::collection(
            PrivacyRetentionRunLog::withoutGlobalScope(TenantScope::class)->where('tenant_id', $this->tenantId($request))->orderByDesc('started_at')->paginate(25),
        );
    }
}
