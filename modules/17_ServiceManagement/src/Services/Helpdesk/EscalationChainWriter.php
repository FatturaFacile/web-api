<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use Illuminate\Support\Facades\DB;
use Modules\ServiceManagement\Models\EscalationChain;
use Modules\ServiceManagement\Models\EscalationStep;
use Modules\ServiceManagement\Models\SLAPolicy;

/**
 * Saves escalation chains (#43) with their steps in one transaction. On update the steps sent replace the existing ones.
 */
final class EscalationChainWriter
{
    /**
     * @param  array{code: string, name: string, is_active?: bool, steps: list<array<string, mixed>>}  $data
     */
    public function create(string $tenantId, array $data): EscalationChain
    {
        return DB::transaction(function () use ($tenantId, $data): EscalationChain {
            $chain = EscalationChain::query()->create([
                'tenant_id' => $tenantId,
                'code' => $data['code'],
                'name' => $data['name'],
                'is_active' => $data['is_active'] ?? true,
            ]);
            $this->replaceSteps($chain, $data['steps']);

            return $chain->load('steps');
        });
    }

    /**
     * @param  array<string, mixed>  $data  code, name, is_active and steps, each optional
     */
    public function update(EscalationChain $chain, array $data): EscalationChain
    {
        return DB::transaction(function () use ($chain, $data): EscalationChain {
            $chain->update(array_intersect_key($data, array_flip(['code', 'name', 'is_active'])));

            if (isset($data['steps'])) {
                $this->replaceSteps($chain, $data['steps']);
            }

            return $chain->refresh()->load('steps');
        });
    }

    /**
     * Deletes the chain; the policies that pointed to it keep running without escalation.
     */
    public function delete(EscalationChain $chain): void
    {
        DB::transaction(function () use ($chain): void {
            SLAPolicy::query()->where('tenant_id', $chain->tenant_id)->where('escalation_chain_id', $chain->id)->update(['escalation_chain_id' => null]);
            EscalationStep::query()->where('chain_id', $chain->id)->delete();
            $chain->delete();
        });
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function replaceSteps(EscalationChain $chain, array $steps): void
    {
        EscalationStep::query()->where('chain_id', $chain->id)->delete();

        foreach ($steps as $position => $step) {
            EscalationStep::query()->create([
                'tenant_id' => $chain->tenant_id,
                'chain_id' => $chain->id,
                'target' => $step['target'],
                'threshold_percent' => $step['threshold_percent'],
                'action' => $step['action'],
                'notify_user_ids' => $step['notify_user_ids'] ?? null,
                'notify_team_lead' => (bool) ($step['notify_team_lead'] ?? false),
                'team_id' => $step['team_id'] ?? null,
                'priority' => $step['priority'] ?? null,
                'position' => $position,
            ]);
        }
    }
}
