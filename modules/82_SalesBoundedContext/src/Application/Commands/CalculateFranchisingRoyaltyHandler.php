<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

use Illuminate\Support\Facades\DB;
use Modules\SalesBoundedContext\Domain\ValueObjects\DecimalMoney;
use Modules\SalesBoundedContext\Events\FranchisingRoyaltyCalculatedEvent;
use Modules\SalesBoundedContext\Models\SbcFranchisingAffiliate;

final readonly class CalculateFranchisingRoyaltyHandler
{
    public function handle(CalculateFranchisingRoyaltyCommand $command): SbcFranchisingAffiliate
    {
        $data = $command->payload;
        $currency = strtoupper((string) ($data['currency'] ?? 'CHF'));

        $sellOut = DecimalMoney::of((float) ($data['sell_out_turnover'] ?? 125000), $currency);
        $royaltyRate = max(0.0, min(30.0, (float) ($data['royalty_rate_pct'] ?? 5.0)));
        $mktFundRate = max(0.0, min(15.0, (float) ($data['marketing_fund_rate_pct'] ?? 2.0)));

        $royaltyAmount = $sellOut->percentage($royaltyRate);
        $mktFundAmount = $sellOut->percentage($mktFundRate);
        $totalFee = $royaltyAmount->add($mktFundAmount);

        $networkAvg = DecimalMoney::of((float) ($data['network_avg_turnover'] ?? 100000), $currency);
        $benchmarkPct = $networkAvg->toFloat() > 0.0
            ? round(($sellOut->toFloat() / $networkAvg->toFloat()) * 100.0, 2)
            : 100.0;

        $affiliate = DB::transaction(function () use (
            $command,
            $data,
            $currency,
            $sellOut,
            $royaltyRate,
            $mktFundRate,
            $royaltyAmount,
            $mktFundAmount,
            $totalFee,
            $networkAvg,
            $benchmarkPct,
        ): SbcFranchisingAffiliate {
            return SbcFranchisingAffiliate::query()->create([
                'tenant_id' => $command->tenantId,
                'dossier_id' => $data['dossier_id'] ?? null,
                'affiliate_code' => (string) ($data['affiliate_code'] ?? 'FRN-'.strtoupper(substr(md5((string) now()->timestamp.mt_rand()), 0, 5))),
                'franchisor_brand' => (string) ($data['franchisor_brand'] ?? 'Alpine Retail Group'),
                'store_name' => (string) $data['store_name'],
                'country' => strtoupper((string) ($data['country'] ?? 'CH')),
                'currency' => $currency,
                'sell_out_turnover' => $sellOut->roundHalfUp(),
                'royalty_rate_pct' => $royaltyRate,
                'marketing_fund_rate_pct' => $mktFundRate,
                'calculated_royalty_amount' => $royaltyAmount->roundHalfUp(),
                'calculated_marketing_fund_amount' => $mktFundAmount->roundHalfUp(),
                'total_fee_invoiceable' => $totalFee->roundHalfUp(),
                'pushed_price_list_version' => (string) ($data['pushed_price_list_version'] ?? 'PL-FRANCHISE-2026-V2'),
                'network_avg_turnover' => $networkAvg->roundHalfUp(),
                'benchmark_index_pct' => $benchmarkPct,
                'status' => (string) ($data['status'] ?? 'royalties_invoiced'),
            ]);
        });

        FranchisingRoyaltyCalculatedEvent::dispatch(
            $command->tenantId,
            (string) $affiliate->id,
            $totalFee->toFloat(),
            $benchmarkPct,
        );

        return $affiliate;
    }
}
