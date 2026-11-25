<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

use Illuminate\Support\Facades\DB;
use Modules\SalesBoundedContext\Domain\ValueObjects\DecimalMoney;
use Modules\SalesBoundedContext\Events\GdoRebateAccruedOrSettledEvent;
use Modules\SalesBoundedContext\Models\SbcDdtInvoice;
use Modules\SalesBoundedContext\Models\SbcGdoRebateAgreement;

final readonly class CalculateGdoRebateAndAccrualHandler
{
    public function handle(CalculateGdoRebateAndAccrualCommand $command): SbcGdoRebateAgreement
    {
        if ($command->settleCreditNote && $command->agreementId !== null) {
            return $this->settleWithCreditNote($command->tenantId, $command->agreementId);
        }

        $data = $command->payload;
        $currency = strtoupper((string) ($data['currency'] ?? 'EUR'));

        /** @var list<array<string, float|int>> $tiers */
        $tiers = is_array($data['tier_brackets'] ?? null)
            ? $data['tier_brackets']
            : [
                ['min_volume' => 100000, 'rate_pct' => 2.5],
                ['min_volume' => 250000, 'rate_pct' => 4.0],
                ['min_volume' => 500000, 'rate_pct' => 6.0],
            ];

        $sellInVolume = DecimalMoney::of((float) ($data['sell_in_volume_amount'] ?? 320000), $currency);
        $sellOutContrib = DecimalMoney::of((float) ($data['sell_out_promo_contribution'] ?? 4500), $currency);

        $resolvedRate = 0.0;
        foreach ($tiers as $tier) {
            if ($sellInVolume->toFloat() >= (float) ($tier['min_volume'] ?? 0)) {
                $resolvedRate = max($resolvedRate, (float) ($tier['rate_pct'] ?? 0));
            }
        }

        $annualPfa = $sellInVolume->percentage($resolvedRate)->add($sellOutContrib);
        $accruedMonths = max(1, min(12, (int) ($data['accrued_months_count'] ?? 11)));
        $monthlyRateo = DecimalMoney::of($annualPfa->toFloat() / 12.0, $currency);
        $totalProvisioned = DecimalMoney::of($monthlyRateo->toFloat() * $accruedMonths, $currency);

        $agreement = DB::transaction(function () use (
            $command,
            $data,
            $currency,
            $tiers,
            $sellInVolume,
            $sellOutContrib,
            $resolvedRate,
            $annualPfa,
            $monthlyRateo,
            $accruedMonths,
            $totalProvisioned,
        ): SbcGdoRebateAgreement {
            return SbcGdoRebateAgreement::query()->create([
                'tenant_id' => $command->tenantId,
                'dossier_id' => $data['dossier_id'] ?? null,
                'agreement_code' => (string) ($data['agreement_code'] ?? 'GDO-PFA-'.strtoupper(substr(md5((string) now()->timestamp.mt_rand()), 0, 5))),
                'gdo_chain_name' => (string) $data['gdo_chain_name'],
                'contract_year' => (int) ($data['contract_year'] ?? 2026),
                'currency' => $currency,
                'tier_brackets' => $tiers,
                'sell_in_volume_amount' => $sellInVolume->roundHalfUp(),
                'sell_out_promo_contribution' => $sellOutContrib->roundHalfUp(),
                'resolved_tier_rate_pct' => $resolvedRate,
                'annual_pfa_rebate_amount' => $annualPfa->roundHalfUp(),
                'monthly_accrual_rateo' => $monthlyRateo->roundHalfUp(),
                'accrued_months_count' => $accruedMonths,
                'total_provisioned_accrual' => $totalProvisioned->roundHalfUp(),
                'settlement_status' => $accruedMonths === 12 ? 'ready_for_settlement' : 'accruing',
            ]);
        });

        GdoRebateAccruedOrSettledEvent::dispatch(
            $command->tenantId,
            (string) $agreement->id,
            (string) $agreement->settlement_status,
            (float) $agreement->total_provisioned_accrual,
        );

        return $agreement;
    }

    private function settleWithCreditNote(string $tenantId, string $agreementId): SbcGdoRebateAgreement
    {
        $agreement = DB::transaction(function () use ($tenantId, $agreementId): SbcGdoRebateAgreement {
            $agreement = SbcGdoRebateAgreement::query()
                ->where('tenant_id', $tenantId)
                ->findOrFail($agreementId);

            if ($agreement->settlement_credit_note_id !== null) {
                return $agreement;
            }

            $net = DecimalMoney::of((float) $agreement->total_provisioned_accrual, $agreement->currency);
            $tax = $net->percentage(22.0);
            $gross = $net->add($tax);

            $creditNote = SbcDdtInvoice::query()->create([
                'tenant_id' => $tenantId,
                'dossier_id' => $agreement->dossier_id,
                'document_kind' => 'credit_note',
                'document_number' => 'NC-PFA-'.strtoupper(substr(md5((string) $agreement->id), 0, 6)),
                'sdi_doc_type' => 'TD04',
                'currency' => $agreement->currency,
                'total_net' => $net->roundHalfUp(),
                'total_tax' => $tax->roundHalfUp(),
                'total_gross' => $gross->roundHalfUp(),
                'issue_date' => now()->toDateString(),
                'status' => 'issued',
            ]);

            $agreement->settlement_credit_note_id = $creditNote->id;
            $agreement->settlement_status = 'settled_credit_note';
            $agreement->save();

            return $agreement;
        });

        GdoRebateAccruedOrSettledEvent::dispatch(
            $tenantId,
            (string) $agreement->id,
            (string) $agreement->settlement_status,
            (float) $agreement->total_provisioned_accrual,
            $agreement->settlement_credit_note_id !== null ? (string) $agreement->settlement_credit_note_id : null,
        );

        return $agreement;
    }
}
