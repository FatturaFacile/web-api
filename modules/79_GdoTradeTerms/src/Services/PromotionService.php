<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Services;

use Illuminate\Support\Carbon;
use Modules\GdoTradeTerms\Domain\Enums\ContributionMode;
use Modules\GdoTradeTerms\Domain\Enums\PromotionStatus;
use Modules\GdoTradeTerms\Domain\Enums\PromotionType;
use Modules\GdoTradeTerms\Domain\Enums\SettlementLineStatus;
use Modules\GdoTradeTerms\Domain\Enums\SourceType;
use Modules\GdoTradeTerms\Exceptions\GdoTradeTermsException;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Models\GdoPromotion;
use Modules\GdoTradeTerms\Models\GdoSettlementLine;
use Modules\GdoTradeTerms\Support\Money;
use Modules\GdoTradeTerms\Support\NotNull;
use Modules\GdoTradeTerms\Support\ScopeOverlap;

/**
 * Trade promotions and contributions of a contract. The window sits inside the contract, the contribution must be coherent
 * with its mode (a rate, a unit amount or a fixed amount), sell-out contributions need the sell-out mode, and two live
 * promotions of the same type may not overlap on the same products and stores.
 */
final class PromotionService
{
    public function __construct(private readonly ContractService $contracts) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(GdoContract $contract, ?GdoPromotion $promotion, array $data, ?string $userId): GdoPromotion
    {
        $this->contracts->assertEditable($contract);

        if ($promotion !== null && $this->hasSettled($promotion)) {
            throw new GdoTradeTermsException('promotion_settled', 409);
        }

        $merged = [...($promotion === null ? [] : $this->snapshot($promotion)), ...$data];
        $start = Carbon::parse((string) $merged['starts_on']);
        $end = Carbon::parse((string) $merged['ends_on']);

        if ($end->lt($start)) {
            throw new GdoTradeTermsException('period_invalid');
        }

        if ($start->lt($contract->starts_on) || $end->gt($contract->ends_on)) {
            throw new GdoTradeTermsException('window_outside_contract');
        }

        $this->assertContribution($merged);

        $attributes = NotNull::strip(array_intersect_key($data, array_flip([
            'code', 'name', 'promotion_type', 'date_basis', 'starts_on', 'ends_on', 'contribution_mode', 'rate_percent', 'unit_amount',
            'fixed_amount', 'fixed_accrual', 'cap_amount', 'category_ids', 'brand_ids', 'product_ids', 'delivery_point_ids', 'notes',
        ])), ['fixed_accrual']);

        $model = $promotion ?? new GdoPromotion(['tenant_id' => $contract->tenant_id, 'contract_id' => $contract->id, 'status' => PromotionStatus::Draft->value, 'created_by' => $userId]);
        $model->fill([...$attributes, 'updated_by' => $userId]);
        $this->assertNoOverlap($contract, $model);
        $model->save();

        return $model->refresh();
    }

    public function transition(GdoPromotion $promotion, string $action, ?string $userId): GdoPromotion
    {
        $contract = GdoContract::query()->findOrFail($promotion->contract_id);
        $this->contracts->assertEditable($contract);

        $target = match ($action) {
            'activate' => PromotionStatus::Active,
            'cancel' => PromotionStatus::Cancelled,
            'end' => PromotionStatus::Ended,
            default => throw new GdoTradeTermsException('invalid_action'),
        };

        $allowed = match ($action) {
            'activate' => [PromotionStatus::Draft],
            'cancel' => [PromotionStatus::Draft, PromotionStatus::Active],
            'end' => [PromotionStatus::Active],
        };

        if (! in_array($promotion->status, $allowed, true)) {
            throw new GdoTradeTermsException('invalid_transition', 409, ['from' => $promotion->status->value, 'to' => $target->value]);
        }

        if ($action === 'cancel' && $this->hasSettled($promotion)) {
            throw new GdoTradeTermsException('promotion_settled', 409);
        }

        if ($action === 'activate') {
            $this->assertNoOverlap($contract, $promotion, true);
        }

        $promotion->update(['status' => $target->value, 'updated_by' => $userId]);

        return $promotion->refresh();
    }

    public function delete(GdoPromotion $promotion, ?string $userId): void
    {
        if ($promotion->status !== PromotionStatus::Draft) {
            throw new GdoTradeTermsException('promotion_not_deletable', 409);
        }

        $promotion->update(['deleted_by' => $userId]);
        $promotion->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertContribution(array $data): void
    {
        $type = PromotionType::from((string) $data['promotion_type']);
        $mode = ContributionMode::from((string) $data['contribution_mode']);

        $valid = match ($mode) {
            ContributionMode::PercentOfSales => $this->positive($data['rate_percent'] ?? null),
            ContributionMode::PerUnit => $this->positive($data['unit_amount'] ?? null),
            ContributionMode::FixedAmount => $this->positive($data['fixed_amount'] ?? null),
            ContributionMode::PercentOfSellOut => $this->positive($data['rate_percent'] ?? null) || $this->positive($data['unit_amount'] ?? null),
        };

        if (! $valid) {
            throw new GdoTradeTermsException('contribution_value_required');
        }

        if ($mode === ContributionMode::PercentOfSellOut && ! $type->readsSellOut()) {
            throw new GdoTradeTermsException('sell_out_mode_requires_sell_out_type');
        }

        if ($type->readsSellOut() && $mode !== ContributionMode::PercentOfSellOut) {
            throw new GdoTradeTermsException('sell_out_type_requires_sell_out_mode');
        }

        if (isset($data['rate_percent']) && Money::compare((string) $data['rate_percent'], '100') > 0) {
            throw new GdoTradeTermsException('rate_invalid');
        }
    }

    private function positive(mixed $value): bool
    {
        return $value !== null && $value !== '' && Money::isPositive((string) $value);
    }

    private function assertNoOverlap(GdoContract $contract, GdoPromotion $promotion, bool $activating = false): void
    {
        if (! $activating && $promotion->status === PromotionStatus::Cancelled) {
            return;
        }

        $mine = $this->scope($promotion);

        foreach (GdoPromotion::query()->where('contract_id', $contract->id)->where('status', '!=', PromotionStatus::Cancelled->value)->whereKeyNot($promotion->id)->get() as $other) {
            if ($other->promotion_type === $promotion->promotion_type && ScopeOverlap::overlaps($promotion->starts_on, $promotion->ends_on, $mine, $other->starts_on, $other->ends_on, $this->scope($other))) {
                throw new GdoTradeTermsException('promotion_overlap', 409, ['code' => $other->code]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function scope(GdoPromotion $promotion): array
    {
        return ['product_ids' => $promotion->product_ids, 'category_ids' => $promotion->category_ids, 'brand_ids' => $promotion->brand_ids, 'delivery_point_ids' => $promotion->delivery_point_ids];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(GdoPromotion $promotion): array
    {
        return [
            'promotion_type' => $promotion->promotion_type->value,
            'contribution_mode' => $promotion->contribution_mode->value,
            'starts_on' => $promotion->starts_on->toDateString(),
            'ends_on' => $promotion->ends_on->toDateString(),
            'rate_percent' => $promotion->rate_percent,
            'unit_amount' => $promotion->unit_amount,
            'fixed_amount' => $promotion->fixed_amount,
        ];
    }

    private function hasSettled(GdoPromotion $promotion): bool
    {
        return GdoSettlementLine::query()->where('source_type', SourceType::Promotion->value)->where('source_id', $promotion->id)->where('status', SettlementLineStatus::Settled->value)->exists();
    }
}
