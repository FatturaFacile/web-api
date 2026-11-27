<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

use Illuminate\Support\Str;
use Modules\SalesBoundedContext\Domain\Enums\CommissionAccrualBasis;
use Modules\SalesBoundedContext\Domain\ValueObjects\DecimalMoney;
use Modules\SalesBoundedContext\Events\CommissionStatementCalculatedEvent;
use Modules\SalesBoundedContext\Models\SbcCommissionStatement;

final readonly class CalculateAgentCommissionStatementHandler
{
    public function handle(CalculateAgentCommissionStatementCommand $command): SbcCommissionStatement
    {
        $data = $command->payload;
        $jurisdiction = strtoupper((string) ($data['agent_jurisdiction'] ?? 'IT'));
        $currency = strtoupper((string) ($data['currency'] ?? ($jurisdiction === 'IT' ? 'EUR' : 'CHF')));
        $basis = CommissionAccrualBasis::from((string) ($data['accrual_basis'] ?? 'collected'));
        $base = DecimalMoney::of((float) ($data['commissionable_base'] ?? 0), $currency);
        $splitOverridePct = (float) ($data['split_override_pct'] ?? 100.0);

        $tiers = is_array($data['tier_rules'] ?? null) && $data['tier_rules'] !== []
            ? $data['tier_rules']
            : [
                ['up_to' => 50000, 'rate_pct' => 5.0],
                ['up_to' => 150000, 'rate_pct' => 7.0],
                ['up_to' => null, 'rate_pct' => 9.0],
            ];

        // Progressive tiered commission calculation
        $remaining = $base->toFloat();
        $prevLimit = 0.0;
        $grossCommissionFloat = 0.0;
        foreach ($tiers as $tier) {
            if ($remaining <= 0.0) {
                break;
            }
            $upTo = isset($tier['up_to']) ? (float) $tier['up_to'] : null;
            $rate = (float) ($tier['rate_pct'] ?? 5.0);
            $slice = $upTo !== null ? min($remaining, max(0.0, $upTo - $prevLimit)) : $remaining;
            $grossCommissionFloat += ($slice * $rate) / 100.0;
            $remaining -= $slice;
            if ($upTo !== null) {
                $prevLimit = $upTo;
            }
        }

        $grossCommission = DecimalMoney::of($grossCommissionFloat, $currency)->percentage($splitOverridePct);

        // Italian Enasarco (17% total: 8.5% company + 8.5% agent withholding) + FIRR 4%
        $enasarcoCompany = $jurisdiction === 'IT' ? $grossCommission->percentage(8.5) : DecimalMoney::of(0, $currency);
        $enasarcoAgent = $jurisdiction === 'IT' ? $grossCommission->percentage(8.5) : DecimalMoney::of(0, $currency);
        $firrAccrual = $jurisdiction === 'IT' ? $grossCommission->percentage(4.0) : DecimalMoney::of(0, $currency);
        $netPayable = $grossCommission->subtract($enasarcoAgent);

        $statement = SbcCommissionStatement::create([
            'tenant_id' => $command->tenantId,
            'statement_number' => (string) ($data['statement_number'] ?? 'EC-'.strtoupper(Str::random(6))),
            'agent_name' => (string) $data['agent_name'],
            'agent_jurisdiction' => $jurisdiction,
            'accrual_basis' => $basis,
            'period_label' => (string) ($data['period_label'] ?? now()->format('Y-m')),
            'currency' => $currency,
            'commissionable_base' => $base->roundHalfUp(),
            'tier_rules' => $tiers,
            'split_override_pct' => number_format($splitOverridePct, 2, '.', ''),
            'gross_commission_amount' => $grossCommission->roundHalfUp(),
            'enasarco_company_amount' => $enasarcoCompany->roundHalfUp(),
            'enasarco_agent_withholding' => $enasarcoAgent->roundHalfUp(),
            'firr_accrual_amount' => $firrAccrual->roundHalfUp(),
            'net_payable_amount' => $netPayable->roundHalfUp(),
            'status' => 'calculated',
        ]);

        CommissionStatementCalculatedEvent::dispatch(
            $command->tenantId,
            (string) $statement->id,
            $statement->agent_name,
            $basis->value,
            $netPayable->roundHalfUp(),
        );

        return $statement;
    }
}
