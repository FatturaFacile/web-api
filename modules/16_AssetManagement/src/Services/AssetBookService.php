<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Services;

use Carbon\CarbonImmutable;
use Modules\AssetManagement\Models\AssetBook;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\FiscalDepreciationRate;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Support\AssetLedger;
use Modules\AssetManagement\Support\Money;

/**
 * Keeps the parallel books of an asset. The statutory (civil) book mirrors the asset master record and posts to the
 * General Ledger; the tax (fiscal) book takes the fiscal parameters of the category and falls back to the civil ones
 * where the category leaves them empty; IFRS and management books start from the civil parameters.
 */
final class AssetBookService
{
    /**
     * The book of the asset for a ledger, created from the asset and its category when missing. Callers that change
     * values must hold the row lock of the asset.
     */
    public function bookFor(FixedAsset $asset, string $ledgerType, bool $persist = true): AssetBook
    {
        $ledgerType = AssetLedger::normalize($ledgerType);

        $book = AssetBook::where('tenant_id', $asset->tenant_id)
            ->where('asset_id', $asset->id)
            ->where('ledger_type', $ledgerType)
            ->first();

        if ($book !== null) {
            if ($ledgerType === AssetLedger::Statutory) {
                $this->mirrorMaster($book, $asset);
                if ($persist && $book->isDirty()) {
                    $book->save();
                }
            }

            return $book;
        }

        $book = $this->newBook($asset, $ledgerType);

        if ($persist) {
            $book->save();
        }

        return $book;
    }

    /**
     * Creates the statutory and tax books of a capitalized asset.
     */
    public function openBooks(FixedAsset $asset): void
    {
        $this->bookFor($asset, AssetLedger::Statutory);
        $this->bookFor($asset, AssetLedger::Tax);
    }

    /**
     * Writes the values of a statutory book back to the asset master record (without saving it).
     */
    public function applyToMaster(AssetBook $book, FixedAsset $asset): void
    {
        $asset->accumulated_depreciation = $book->accumulated_depreciation;
        $asset->carrying_amount = $book->net_book_value;
        $asset->consumed_units = $book->consumed_units;
        $asset->is_fully_depreciated = $book->is_fully_depreciated;
    }

    /**
     * Scales every book of the asset by a remaining fraction (partial disposal), or closes them all when nothing
     * remains. Returns the disposed share of each book, keyed by ledger type.
     *
     * @return array<string, array{cost: string, accumulated_depreciation: string, net_book_value: string}>
     */
    public function disposeShare(FixedAsset $asset, string $disposedFraction): array
    {
        $shares = [];
        $books = AssetBook::where('tenant_id', $asset->tenant_id)->where('asset_id', $asset->id)->get();

        foreach ($books as $book) {
            $cost = Money::round(Money::mul($book->cost, $disposedFraction));
            $accumulated = Money::round(Money::mul($book->accumulated_depreciation, $disposedFraction));
            $netBookValue = Money::round(Money::mul($book->net_book_value, $disposedFraction));

            $shares[(string) $book->ledger_type] = ['cost' => $cost, 'accumulated_depreciation' => $accumulated, 'net_book_value' => $netBookValue];

            $book->update([
                'cost' => Money::round(Money::sub($book->cost, $cost)),
                'salvage_value' => Money::round(Money::sub($book->salvage_value, Money::mul($book->salvage_value, $disposedFraction))),
                'depreciable_base' => Money::round(Money::sub($book->depreciable_base, Money::mul($book->depreciable_base, $disposedFraction))),
                'accumulated_depreciation' => Money::round(Money::sub($book->accumulated_depreciation, $accumulated)),
                'net_book_value' => Money::round(Money::sub($book->net_book_value, $netBookValue)),
                'is_fully_depreciated' => Money::compare($disposedFraction, '1') >= 0 ? true : $book->is_fully_depreciated,
            ]);
        }

        return $shares;
    }

    /**
     * Adds a capitalized improvement to every book of the asset (optionally skipping the tax book):
     * cost, depreciable base, net book value, and optional useful life extension grow prospectively.
     */
    public function addCost(FixedAsset $asset, string $amount, bool $affectsTaxBook = true, int $extendsUsefulLifeMonths = 0): void
    {
        $books = AssetBook::where('tenant_id', $asset->tenant_id)->where('asset_id', $asset->id)->get();

        foreach ($books as $book) {
            if ($book->ledger_type === AssetLedger::Statutory) {
                $this->mirrorMaster($book, $asset);
                $book->save();

                continue;
            }

            if ($book->ledger_type === AssetLedger::Tax && ! $affectsTaxBook) {
                continue;
            }

            $updates = [
                'cost' => Money::round(Money::add($book->cost, $amount)),
                'depreciable_base' => Money::round(Money::add($book->depreciable_base, $amount)),
                'net_book_value' => Money::round(Money::add($book->net_book_value, $amount)),
                'is_fully_depreciated' => false,
            ];

            if ($extendsUsefulLifeMonths > 0) {
                $updates['useful_life_months'] = max(1, (int) $book->useful_life_months + $extendsUsefulLifeMonths);
            }

            $book->update($updates);
        }
    }

    /**
     * Configures or overrides the parameters of a specific book (commonly the parallel fiscal CH/IT book).
     *
     * @param  array<string, mixed>  $params
     */
    public function configureBook(FixedAsset $asset, string $ledgerType, array $params): AssetBook
    {
        $book = $this->bookFor($asset, $ledgerType);
        $rate = ! empty($params['fiscal_rate_id'])
            ? FiscalDepreciationRate::withoutGlobalScopes()
                ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $asset->tenant_id))
                ->find($params['fiscal_rate_id'])
            : null;

        if ($rate !== null) {
            $this->applyFiscalRateToBook($book, $rate, (bool) ($params['immediate_expensing'] ?? $asset->immediate_expensing_applied), $params['fiscal_regime'] ?? null);
        }

        foreach (['fiscal_regime', 'depreciation_method', 'pro_rata_convention'] as $strField) {
            if (array_key_exists($strField, $params) && $params[$strField] !== null) {
                $book->{$strField} = (string) $params[$strField];
            }
        }

        if (array_key_exists('rate_percent', $params)) {
            $book->rate_percent = $params['rate_percent'] !== null ? Money::round((string) $params['rate_percent'], 4) : null;
        }
        if (array_key_exists('useful_life_months', $params) && $params['useful_life_months'] !== null) {
            $book->useful_life_months = max(1, (int) $params['useful_life_months']);
        }
        if (array_key_exists('declining_balance_factor', $params)) {
            $book->declining_balance_factor = $params['declining_balance_factor'] !== null ? Money::round((string) $params['declining_balance_factor'], 4) : null;
        }
        if (array_key_exists('switch_to_straight_line', $params) && $params['switch_to_straight_line'] !== null) {
            $book->switch_to_straight_line = (bool) $params['switch_to_straight_line'];
        }
        if (array_key_exists('immediate_expensing', $params) && $params['immediate_expensing'] !== null) {
            $book->immediate_expensing = (bool) $params['immediate_expensing'];
            if ($book->immediate_expensing) {
                $book->depreciation_method = 'immediate_expensing';
            }
        }
        if (array_key_exists('first_year_half_rate', $params) && $params['first_year_half_rate'] !== null) {
            $book->first_year_half_rate = (bool) $params['first_year_half_rate'];
        }
        if (array_key_exists('pro_memoria_value', $params)) {
            $book->pro_memoria_value = $params['pro_memoria_value'] !== null ? Money::round((string) $params['pro_memoria_value']) : null;
        }

        $book->save();

        return $book->fresh();
    }

    /**
     * Applies a permanent impairment write-down to the tax book when fiscal rules recognise it (e.g. CH CO Art. 960a).
     */
    public function applyImpairmentToTaxBook(FixedAsset $asset, string $lossAmount): void
    {
        $taxBook = $this->bookFor($asset, AssetLedger::Tax);
        $loss = Money::round(Money::max('0', Money::min($lossAmount, $taxBook->net_book_value)));

        if (! Money::isPositive($loss)) {
            return;
        }

        $newNbv = Money::round(Money::sub($taxBook->net_book_value, $loss));
        $newBase = Money::round(Money::max('0', Money::sub($newNbv, $taxBook->salvage_value)));

        $taxBook->update([
            'net_book_value' => $newNbv,
            'depreciable_base' => $newBase,
            'impairment_loss_total' => Money::round(Money::add($taxBook->impairment_loss_total ?? '0', $loss)),
            'is_fully_depreciated' => Money::compare($newNbv, $taxBook->salvage_value) <= 0,
        ]);
    }

    /**
     * After a revaluation, impairment, reversal or capitalized improvement the new carrying amount is depreciated prospectively over the
     * remaining useful life: depreciable base = carrying - salvage, useful life = months left at the valuation date.
     */
    public function rebaseOnRemainingLife(FixedAsset $asset, string $carryingAfter, string $date): void
    {
        $start = $asset->depreciation_start_date ?? ($asset->in_service_date ? CarbonImmutable::parse($asset->in_service_date->format('Y-m-d')) : null);
        $elapsedMonths = 0;
        $elapsedBeforeLastReset = (int) (($asset->metadata ?? [])['life_months_elapsed'] ?? 0);

        if ($start !== null) {
            $date = CarbonImmutable::parse($date);
            $elapsedMonths = max(0, ($date->year - $start->year) * 12 + $date->month - $start->month + 1);
        }

        $remainingMonths = max(1, (int) $asset->useful_life_months - max(0, $elapsedMonths - $elapsedBeforeLastReset));

        $asset->depreciable_base = Money::round(Money::max('0', Money::sub($carryingAfter, $asset->salvage_value)));
        $asset->useful_life_months = $remainingMonths;
        $asset->metadata = [...($asset->metadata ?? []), 'life_months_elapsed' => $elapsedMonths];
    }

    /**
     * A new, unsaved book for the ledger, with the parameters of the asset and its category.
     */
    private function newBook(FixedAsset $asset, string $ledgerType): AssetBook
    {
        $category = AssetCategory::find($asset->category_id);
        $book = new AssetBook([
            'tenant_id' => $asset->tenant_id,
            'asset_id' => $asset->id,
            'ledger_type' => $ledgerType,
            'posts_to_gl' => $ledgerType === AssetLedger::Statutory,
            'switch_to_straight_line' => true,
            'immediate_expensing' => false,
            'first_year_half_rate' => false,
            'impairment_loss_total' => '0.00',
        ]);

        $this->mirrorMaster($book, $asset, $category);

        if ($ledgerType === AssetLedger::Statutory) {
            $book->fiscal_regime = 'civil_linear';

            return $book;
        }

        $cost = $this->costOf($asset);
        $book->accumulated_depreciation = '0.00';
        $book->net_book_value = $cost;
        $book->depreciable_base = Money::round(Money::max('0', Money::sub($cost, $book->salvage_value)));
        $book->consumed_units = '0.00';
        $book->is_fully_depreciated = false;
        $book->last_period_code = null;

        if ($ledgerType === AssetLedger::Tax && $category !== null) {
            if ($category->fiscal_depreciation_method !== null) {
                $book->depreciation_method = (string) $category->fiscal_depreciation_method;
            }
            if (Money::isPositive($category->fiscal_rate_percent)) {
                $book->rate_percent = $category->fiscal_rate_percent;
            }
            if ((int) $category->fiscal_useful_life_years > 0) {
                $book->useful_life_months = (int) $category->fiscal_useful_life_years * 12;
                if (! Money::isPositive($category->fiscal_rate_percent)) {
                    $book->rate_percent = null;
                }
            }
            if ($category->fiscal_pro_rata_convention !== null) {
                $book->pro_rata_convention = (string) $category->fiscal_pro_rata_convention;
            }
        }

        if ($ledgerType === AssetLedger::Tax) {
            $fiscalRate = $asset->fiscal_rate_id !== null
                ? FiscalDepreciationRate::withoutGlobalScopes()->find($asset->fiscal_rate_id)
                : null;

            if ($fiscalRate !== null) {
                $this->applyFiscalRateToBook($book, $fiscalRate, (bool) $asset->immediate_expensing_applied);
            } elseif ($asset->immediate_expensing_applied) {
                $book->fiscal_regime = 'ch_afc_immediate';
                $book->depreciation_method = 'immediate_expensing';
                $book->immediate_expensing = true;
            }
        }

        return $book;
    }

    private function applyFiscalRateToBook(AssetBook $book, FiscalDepreciationRate $rate, bool $immediateExpensing = false, ?string $preferredRegime = null): void
    {
        $book->fiscal_rate_id = $rate->id;

        if ($immediateExpensing) {
            $book->fiscal_regime = 'ch_afc_immediate';
            $book->depreciation_method = 'immediate_expensing';
            $book->immediate_expensing = true;
            $book->switch_to_straight_line = false;
            $book->first_year_half_rate = false;
            $book->pro_memoria_value = $rate->jurisdiction === 'CH' && Money::isZero($book->salvage_value) ? '1.00' : null;

            return;
        }

        if ($rate->jurisdiction === 'CH') {
            $useLinear = $preferredRegime === 'ch_afc_linear' || ! Money::isPositive($rate->declining_rate_percent);
            if ($useLinear && Money::isPositive($rate->linear_rate_percent)) {
                $book->fiscal_regime = 'ch_afc_linear';
                $book->depreciation_method = 'straight_line';
                $book->rate_percent = $rate->linear_rate_percent;
                $book->switch_to_straight_line = true;
                $book->first_year_half_rate = false;
                $book->immediate_expensing = false;
            } elseif (Money::isPositive($rate->declining_rate_percent)) {
                $book->fiscal_regime = 'ch_afc_degressive';
                $book->depreciation_method = 'declining_balance';
                $book->rate_percent = $rate->declining_rate_percent;
                $book->switch_to_straight_line = false;
                $book->first_year_half_rate = false;
                $book->immediate_expensing = false;
                $book->pro_rata_convention = 'full';
                $book->pro_memoria_value = Money::isZero($book->salvage_value) ? '1.00' : null;
            }

            return;
        }

        $book->fiscal_regime = 'it_dm_1988';
        $book->depreciation_method = 'straight_line';
        $book->rate_percent = $rate->linear_rate_percent;
        $book->first_year_half_rate = (bool) $rate->first_year_half_rate;
        $book->pro_rata_convention = $rate->first_year_half_rate ? 'half_year' : 'full';
        $book->switch_to_straight_line = true;
        $book->immediate_expensing = false;
    }

    /**
     * Copies the depreciation parameters and values of the asset master record into a book.
     */
    private function mirrorMaster(AssetBook $book, FixedAsset $asset, ?AssetCategory $category = null): void
    {
        $category ??= AssetCategory::find($asset->category_id);

        $book->depreciation_method = (string) ($asset->depreciation_method ?: 'straight_line');
        $book->useful_life_months = max(1, (int) ($asset->useful_life_months ?: ((int) $asset->useful_life_years * 12)));
        $book->rate_percent = $book->depreciation_method !== 'straight_line' && Money::isPositive($asset->depreciation_rate_percent)
            ? $asset->depreciation_rate_percent
            : null;
        $book->declining_balance_factor = $asset->declining_balance_factor ?? $category?->declining_balance_factor;
        $book->pro_rata_convention = (string) ($asset->pro_rata_convention ?? $category->pro_rata_convention ?? 'monthly');
        $book->depreciation_start_date = $asset->depreciation_start_date ?? $asset->in_service_date ?? $asset->capitalization_date;
        $book->cost = $this->costOf($asset);
        $book->salvage_value = $asset->salvage_value ?? '0.00';
        $book->depreciable_base = $asset->depreciable_base ?? '0.00';
        $book->accumulated_depreciation = $asset->accumulated_depreciation ?? '0.00';
        $book->net_book_value = $asset->carrying_amount ?? '0.00';
        $book->total_expected_units = $asset->total_expected_units ?? '0.00';
        $book->consumed_units = $asset->consumed_units ?? '0.00';
        $book->is_fully_depreciated = (bool) $asset->is_fully_depreciated;
    }

    /**
     * The cost basis of the asset: the capitalized cost once capitalized, else the acquisition cost.
     */
    private function costOf(FixedAsset $asset): string
    {
        return Money::round(Money::isPositive($asset->capitalized_cost) ? $asset->capitalized_cost : $asset->acquisition_cost);
    }
}
