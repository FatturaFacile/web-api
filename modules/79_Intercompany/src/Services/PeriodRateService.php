<?php

declare(strict_types=1);

namespace Modules\Intercompany\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Modules\GeneralLedger\Contracts\ConsolidationRateOverrides;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\Intercompany\Enums\RateType;
use Modules\Intercompany\Exceptions\IntercompanyException;
use Modules\Intercompany\Models\PeriodRate;
use Modules\Intercompany\Support\GroupScope;

/**
 * Closing, average and historical rates the controller fixes for a period end. They win over the published exchange rates
 * when the entities are translated (the translator asks this class through ConsolidationRateOverrides), so a group can
 * consolidate with the rates of its own treasury or auditor. The rows belong to the owner of the group and are read only
 * through its tenant scope; a rate is group-currency units for one unit of the entity currency.
 */
final readonly class PeriodRateService implements ConsolidationRateOverrides
{
    public function __construct(private GroupScope $scope) {}

    public function overridesFor(string $currency, string $groupCurrency, CarbonImmutable $periodEnd): array
    {
        $fixed = [];

        $rows = PeriodRate::query()
            ->where('group_currency', $groupCurrency)
            ->where('currency', $currency)
            ->whereDate('period_end', $periodEnd->toDateString())
            ->get();

        foreach ($rows as $row) {
            $fixed[(string) $row->rate_type] = bcadd((string) $row->rate, '0', 6);
        }

        return $fixed;
    }

    /**
     * @return Collection<int, PeriodRate>
     */
    public function list(User $user, ConsolidationGroup $group, ?string $currency, ?string $rateType, ?string $periodEnd): Collection
    {
        $this->scope->assertReadable($user, $group);

        return PeriodRate::withoutGlobalScopes()
            ->where('tenant_id', $group->tenant_id)
            ->where('group_currency', $group->group_currency)
            ->when($currency, fn ($query) => $query->where('currency', strtoupper($currency)))
            ->when($rateType, fn ($query) => $query->where('rate_type', $rateType))
            ->when($periodEnd, fn ($query) => $query->whereDate('period_end', $periodEnd))
            ->orderByDesc('period_end')
            ->orderBy('currency')
            ->orderBy('rate_type')
            ->get();
    }

    public function upsert(User $user, ConsolidationGroup $group, string $currency, RateType $type, CarbonImmutable $periodEnd, string $rate, ?string $note): PeriodRate
    {
        $this->scope->assertReadable($user, $group);
        $currency = strtoupper($currency);

        if ($currency === strtoupper((string) $group->group_currency)) {
            throw new IntercompanyException('The group currency needs no rate.', 'rate_group_currency');
        }

        if (bccomp($rate, '0', 8) <= 0) {
            throw new IntercompanyException('The rate must be greater than zero.', 'rate_invalid');
        }

        return $this->scope->runAs((string) $group->tenant_id, fn (): PeriodRate => PeriodRate::withoutGlobalScopes()->updateOrCreate(
            [
                'tenant_id' => $group->tenant_id,
                'group_currency' => $group->group_currency,
                'currency' => $currency,
                'rate_type' => $type->value,
                'period_end' => $periodEnd->toDateString(),
            ],
            ['group_id' => $group->id, 'rate' => bcadd($rate, '0', 8), 'note' => $note],
        )->refresh());
    }

    public function delete(User $user, PeriodRate $rate): void
    {
        $group = $rate->group_id === null ? null : ConsolidationGroup::withoutGlobalScopes()->where('tenant_id', $rate->tenant_id)->find($rate->group_id);

        if ($group !== null) {
            $this->scope->assertReadable($user, $group);
        }

        $rate->delete();
    }
}
