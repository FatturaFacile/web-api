<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Services;

use Illuminate\Support\Carbon;
use Modules\GdoTradeTerms\Domain\Enums\LineStatus;
use Modules\GdoTradeTerms\Domain\Enums\SourceType;
use Modules\GdoTradeTerms\Models\GdoAccrualLine;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Support\Money;

/**
 * Where a contract stands at a date: per rebate scale the volume reached, the tier, the year-end projection and the distance
 * to the next bracket; per promotion what it has earned so far; and what is already accrued.
 */
final readonly class ContractProgressService
{
    public function __construct(private EarningsService $earnings) {}

    /**
     * @return array<string, mixed>
     */
    public function progress(GdoContract $contract, Carbon $asOf): array
    {
        $scales = [];
        $earnedScales = '0';
        $projectedScales = '0';

        foreach ($contract->scales as $scale) {
            $projection = $this->earnings->scaleProjection($scale, $contract, $asOf);
            $earned = $this->earnings->scaleEarned($scale, $contract, $asOf);
            $earnedScales = Money::add($earnedScales, $scale->is_active ? $earned['earned'] : '0');
            $projectedScales = Money::add($projectedScales, $scale->is_active ? (string) $projection['projected_rebate'] : '0');

            $scales[] = [
                'id' => $scale->id,
                'code' => $scale->code,
                'name' => $scale->name,
                'is_active' => $scale->is_active,
                'tier_mode' => $scale->tier_mode->value,
                'basis' => $scale->basis->value,
                'volume_mode' => $scale->volume_mode->value,
                'window_start' => $scale->windowStart($contract)->toDateString(),
                'window_end' => $scale->windowEnd($contract)->toDateString(),
                'tiers' => $scale->tierRows(),
                'earned_to_date' => $earned['earned'],
                'accrued' => $this->accrued(SourceType::Scale, $scale->id),
                'projection' => $projection,
            ];
        }

        $promotions = [];
        $earnedPromotions = '0';

        foreach ($contract->promotions as $promotion) {
            $earned = $this->earnings->promotionEarned($promotion, $contract, $asOf);
            $earnedPromotions = Money::add($earnedPromotions, $earned['earned']);

            $promotions[] = [
                'id' => $promotion->id,
                'code' => $promotion->code,
                'name' => $promotion->name,
                'promotion_type' => $promotion->promotion_type->value,
                'status' => $promotion->status->value,
                'starts_on' => $promotion->starts_on->toDateString(),
                'ends_on' => $promotion->ends_on->toDateString(),
                'earned_to_date' => $earned['earned'],
                'base_value' => $earned['base_value'],
                'cap_amount' => $promotion->cap_amount === null ? null : Money::round((string) $promotion->cap_amount),
                'accrued' => $this->accrued(SourceType::Promotion, $promotion->id),
            ];
        }

        return [
            'contract_id' => $contract->id,
            'as_of' => $asOf->toDateString(),
            'currency' => $contract->currency,
            'effective_on_invoice_discount_percent' => $contract->effectiveOnInvoiceDiscountPercent(),
            'scales' => $scales,
            'promotions' => $promotions,
            'totals' => [
                'earned_scales' => $earnedScales,
                'earned_promotions' => $earnedPromotions,
                'earned_total' => Money::add($earnedScales, $earnedPromotions),
                'projected_scales' => $projectedScales,
                'accrued_total' => Money::from(GdoAccrualLine::query()->where('contract_id', $contract->id)->where('status', '!=', LineStatus::Reversed->value)->sum('delta_amount')),
            ],
        ];
    }

    private function accrued(SourceType $type, string $sourceId): string
    {
        return Money::from(GdoAccrualLine::query()->where('source_type', $type->value)->where('source_id', $sourceId)->where('status', '!=', LineStatus::Reversed->value)->sum('delta_amount'));
    }
}
