<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Queries;

use Illuminate\Database\Eloquent\Builder;
use Modules\AgentCommissions\Models\AgentProfile;
use Modules\AgentCommissions\Models\AgentYearBalance;
use Modules\AgentCommissions\Services\FiscalEngine;
use Modules\AgentCommissions\Services\FiscalParameterResolver;
use Modules\Payroll\Calculation\Dec;

/**
 * Yearly Enasarco and FIRR position of every Italian-regime agent: base against the ceiling (massimale), contributions
 * against the floor (minimale), and the FIRR accrued so far.
 */
final class GetEnasarcoOverviewHandler
{
    public function __construct(
        private readonly FiscalEngine $engine,
        private readonly FiscalParameterResolver $parameters,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(GetEnasarcoOverviewQuery $query): array
    {
        $this->parameters->flush();
        $profiles = AgentProfile::query()
            ->with('representative:id,name')
            ->when($query->agentProfileId !== null, static fn (Builder $builder) => $builder->where('id', $query->agentProfileId))
            ->orderBy('created_at')
            ->get()
            ->filter(fn (AgentProfile $profile): bool => $this->engine->isItalianRegime($profile));
        $balances = [];

        foreach (AgentYearBalance::query()->where('year', $query->year)->whereIn('agent_profile_id', $profiles->modelKeys())->get() as $row) {
            $balances[(string) $row->agent_profile_id] = $row;
        }

        $rows = [];

        foreach ($profiles as $profile) {
            $mandate = $this->engine->mandateOf($profile);
            $balance = $balances[(string) $profile->id] ?? null;
            $massimale = $this->parameters->valueOrNull($query->tenantId, 'enasarco_massimale', $mandate, $query->year);
            $minimale = $this->parameters->valueOrNull($query->tenantId, 'enasarco_minimale', $mandate, $query->year);
            $base = self::figure($balance, 'enasarco_base_ytd', '0');
            $agent = self::figure($balance, 'enasarco_agent_ytd', '0');
            $company = self::figure($balance, 'enasarco_company_ytd', '0');
            $contribution = Dec::add($agent, $company);

            $rows[] = [
                'agent_profile_id' => (string) $profile->id,
                'agent_name' => $profile->representative?->name,
                'mandate' => $mandate,
                'year' => $query->year,
                'commission_ytd' => self::figure($balance, 'commission_ytd', '0.00'),
                'enasarco_base_ytd' => Dec::round($base),
                'enasarco_agent_ytd' => Dec::round($agent),
                'enasarco_company_ytd' => Dec::round($company),
                'massimale' => $massimale === null ? null : Dec::round($massimale),
                'massimale_remaining' => $massimale === null ? null : Dec::round(Dec::max('0', Dec::sub($massimale, $base))),
                'massimale_reached' => $massimale !== null && Dec::cmp($base, $massimale) >= 0,
                'minimale' => $minimale === null ? null : Dec::round($minimale),
                'minimale_reached' => $minimale !== null && Dec::cmp($contribution, $minimale) >= 0,
                'minimale_shortfall' => $minimale === null ? null : Dec::round(Dec::max('0', Dec::sub($minimale, $contribution))),
                'firr_ytd' => self::figure($balance, 'firr_ytd', '0.00'),
                'firr_enabled' => (bool) $profile->firr_enabled,
            ];
        }

        return $rows;
    }

    private static function figure(?AgentYearBalance $balance, string $field, string $default): string
    {
        return $balance === null ? $default : (string) $balance->{$field};
    }
}
