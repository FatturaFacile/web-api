<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\AgentCommissions\Domain\Enums\AgentType;
use Modules\AgentCommissions\Domain\Enums\RateType;
use Modules\AgentCommissions\Exceptions\AgentCommissionException;
use Modules\AgentCommissions\Models\AgentProfile;
use Modules\AgentCommissions\Models\CommissionRule;
use Modules\AgentCommissions\Models\CommissionRuleTier;
use Modules\AgentCommissions\Models\FiscalParameter;
use Modules\AgentCommissions\Models\Zone;
use Modules\Invoicing\Models\SalesRepresentative;

/**
 * Master data of the module: agent profiles (contract conditions), commission rules with their tiers, zones and the
 * tenant overrides of the fiscal parameters. Validation of the business rules lives here, not in the controllers.
 */
final class AgentRegistryService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, AgentProfile>
     */
    public function agents(array $filters): Collection
    {
        return AgentProfile::query()
            ->with(['representative:id,rep_code,name,email,default_commission_rate', 'parent.representative:id,name'])
            ->when(isset($filters['agent_type']), static fn (Builder $query) => $query->where('agent_type', $filters['agent_type']))
            ->when(isset($filters['is_active']), static fn (Builder $query) => $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Sales representatives that have no agent profile yet.
     *
     * @return Collection<int, SalesRepresentative>
     */
    public function availableRepresentatives(): Collection
    {
        return SalesRepresentative::query()
            ->whereNotIn('id', AgentProfile::query()->select('sales_representative_id'))
            ->orderBy('name')
            ->get(['id', 'rep_code', 'name', 'email', 'default_commission_rate']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createAgent(array $data): AgentProfile
    {
        $this->assertParent(null, $data);

        return AgentProfile::query()->create($data)->refresh()->load(['representative', 'parent.representative']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateAgent(AgentProfile $profile, array $data): AgentProfile
    {
        $this->assertParent($profile, array_merge($profile->only(['agent_type', 'parent_agent_profile_id']), $data));
        $profile->update($data);

        return $profile->refresh()->load(['representative', 'parent.representative']);
    }

    public function deleteAgent(AgentProfile $profile): void
    {
        $profile->delete();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, CommissionRule>
     */
    public function rules(array $filters): Collection
    {
        return CommissionRule::query()
            ->with(['tiers', 'zone:id,code,name'])
            ->when(isset($filters['agent_profile_id']), static fn (Builder $query) => $query->where('agent_profile_id', $filters['agent_profile_id']))
            ->orderBy('priority')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createRule(array $data): CommissionRule
    {
        $tiers = $this->tiersOf($data);
        $this->assertRule($data, $tiers);

        return DB::transaction(function () use ($data, $tiers): CommissionRule {
            $rule = CommissionRule::query()->create($data);
            $this->syncTiers($rule, $tiers);

            return $rule->refresh()->load('tiers');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateRule(CommissionRule $rule, array $data): CommissionRule
    {
        $tiers = array_key_exists('tiers', $data) ? $this->tiersOf($data) : null;
        $merged = array_merge($rule->only(['rate_type', 'rate', 'fixed_amount']), $data);
        $this->assertRule($merged, $tiers ?? $rule->tiers->map(static fn (CommissionRuleTier $tier): array => ['from' => (string) $tier->threshold_from, 'to' => $tier->threshold_to === null ? null : (string) $tier->threshold_to, 'rate' => (string) $tier->rate])->all());

        return DB::transaction(function () use ($rule, $data, $tiers): CommissionRule {
            unset($data['tiers']);
            $rule->update($data);

            if ($tiers !== null) {
                $this->syncTiers($rule, $tiers);
            }

            return $rule->refresh()->load('tiers');
        });
    }

    public function deleteRule(CommissionRule $rule): void
    {
        DB::transaction(function () use ($rule): void {
            $rule->tiers()->delete();
            $rule->delete();
        });
    }

    /**
     * @return Collection<int, Zone>
     */
    public function zones(): Collection
    {
        return Zone::query()->orderBy('code')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveZone(?Zone $zone, array $data): Zone
    {
        if ($zone === null) {
            return Zone::query()->create($data)->refresh();
        }

        $zone->update($data);

        return $zone->refresh();
    }

    public function deleteZone(Zone $zone): void
    {
        CommissionRule::query()->where('zone_id', $zone->id)->update(['zone_id' => null, 'is_active' => false]);
        $zone->delete();
    }

    /**
     * Statutory defaults (global rows) and the overrides of the tenant.
     *
     * @return Collection<int, FiscalParameter>
     */
    public function fiscalParameters(): Collection
    {
        return FiscalParameter::query()->orderBy('code')->orderBy('mandate_type')->orderBy('year')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveFiscalOverride(string $tenantId, array $data): FiscalParameter
    {
        return FiscalParameter::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'code' => $data['code'], 'mandate_type' => $data['mandate_type'] ?? null, 'year' => $data['year'] ?? null],
            ['value' => $data['value'] ?? null, 'payload' => $data['payload'] ?? null],
        );
    }

    public function deleteFiscalOverride(FiscalParameter $parameter): void
    {
        $parameter->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertParent(?AgentProfile $profile, array $data): void
    {
        $parentId = $data['parent_agent_profile_id'] ?? null;

        if ($parentId === null) {
            return;
        }

        $parent = AgentProfile::query()->find($parentId);

        if ($parent === null || ($profile !== null && $parent->id === $profile->id) || $parent->parent_agent_profile_id !== null) {
            throw new AgentCommissionException('Invalid parent agent.', 'invalid_parent');
        }

        if (($data['agent_type'] ?? null) !== AgentType::SubAgent->value) {
            throw new AgentCommissionException('Only a sub-agent has a parent agent.', 'invalid_parent');
        }

        if ($profile !== null && AgentProfile::query()->where('parent_agent_profile_id', $profile->id)->exists()) {
            throw new AgentCommissionException('An agent with sub-agents cannot become a sub-agent.', 'invalid_parent');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{from: string, to: string|null, rate: string}>
     */
    private function tiersOf(array $data): array
    {
        $tiers = array_map(static fn (array $tier): array => [
            'from' => (string) ($tier['threshold_from'] ?? $tier['from'] ?? '0'),
            'to' => ($tier['threshold_to'] ?? $tier['to'] ?? null) === null ? null : (string) ($tier['threshold_to'] ?? $tier['to']),
            'rate' => (string) $tier['rate'],
        ], $data['tiers'] ?? []);
        usort($tiers, static fn (array $a, array $b): int => bccomp($a['from'], $b['from'], 2));

        return $tiers;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{from: string, to: string|null, rate: string}>  $tiers
     */
    private function assertRule(array $data, array $tiers): void
    {
        $type = $data['rate_type'] ?? RateType::Percentage->value;

        if ($type === RateType::Fixed->value) {
            if (! isset($data['fixed_amount'])) {
                throw new AgentCommissionException('A fixed rule needs an amount.', 'rate_required');
            }

            return;
        }

        if ($tiers !== []) {
            if (! TieredRateCalculator::isContiguous($tiers)) {
                throw new AgentCommissionException('Invalid tiers.', 'invalid_tiers');
            }

            return;
        }

        if (! isset($data['rate'])) {
            throw new AgentCommissionException('A percentage rule needs a rate.', 'rate_required');
        }
    }

    /**
     * @param  list<array{from: string, to: string|null, rate: string}>  $tiers
     */
    private function syncTiers(CommissionRule $rule, array $tiers): void
    {
        $rule->tiers()->delete();

        foreach ($tiers as $tier) {
            CommissionRuleTier::query()->create([
                'tenant_id' => $rule->tenant_id,
                'rule_id' => $rule->id,
                'threshold_from' => $tier['from'],
                'threshold_to' => $tier['to'],
                'rate' => $tier['rate'],
            ]);
        }
    }
}
