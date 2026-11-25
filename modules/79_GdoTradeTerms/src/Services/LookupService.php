<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\GdoTradeTerms\Domain\Enums\BaselineSource;
use Modules\GdoTradeTerms\Domain\Enums\ContractStatus;
use Modules\GdoTradeTerms\Domain\Enums\ContributionMode;
use Modules\GdoTradeTerms\Domain\Enums\DateBasis;
use Modules\GdoTradeTerms\Domain\Enums\DeliveryPointType;
use Modules\GdoTradeTerms\Domain\Enums\FixedAccrual;
use Modules\GdoTradeTerms\Domain\Enums\LineStatus;
use Modules\GdoTradeTerms\Domain\Enums\PromotionStatus;
use Modules\GdoTradeTerms\Domain\Enums\PromotionType;
use Modules\GdoTradeTerms\Domain\Enums\RebateBasis;
use Modules\GdoTradeTerms\Domain\Enums\RunStatus;
use Modules\GdoTradeTerms\Domain\Enums\SettlementKind;
use Modules\GdoTradeTerms\Domain\Enums\SettlementLineStatus;
use Modules\GdoTradeTerms\Domain\Enums\SettlementStatus;
use Modules\GdoTradeTerms\Domain\Enums\SourceType;
use Modules\GdoTradeTerms\Domain\Enums\TaxTreatment;
use Modules\GdoTradeTerms\Domain\Enums\TierMode;
use Modules\GdoTradeTerms\Domain\Enums\Treatment;
use Modules\GdoTradeTerms\Domain\Enums\VolumeMode;

/**
 * Reference data of the screens: the enumerations (their labels are in the frontend dictionary served by the API), the standard
 * VAT rates by jurisdiction, and the retailers, categories, brands and ledger accounts a contract can point at.
 */
final class LookupService
{
    /**
     * @return array<string, mixed>
     */
    public function reference(string $tenantId): array
    {
        return [
            'enums' => [
                'contract_status' => ContractStatus::values(),
                'tier_mode' => TierMode::values(),
                'basis' => RebateBasis::values(),
                'volume_mode' => VolumeMode::values(),
                'baseline_source' => BaselineSource::values(),
                'promotion_type' => PromotionType::values(),
                'promotion_status' => PromotionStatus::values(),
                'date_basis' => DateBasis::values(),
                'contribution_mode' => ContributionMode::values(),
                'fixed_accrual' => FixedAccrual::values(),
                'run_status' => RunStatus::values(),
                'line_status' => LineStatus::values(),
                'source_type' => SourceType::values(),
                'settlement_kind' => SettlementKind::values(),
                'settlement_status' => SettlementStatus::values(),
                'settlement_line_status' => SettlementLineStatus::values(),
                'treatment' => Treatment::values(),
                'tax_treatment' => TaxTreatment::values(),
                'delivery_point_type' => DeliveryPointType::values(),
                'jurisdiction' => ['IT', 'CH'],
            ],
            'promotion_treatments' => collect(PromotionType::cases())->mapWithKeys(static fn (PromotionType $type): array => [$type->value => $type->treatment()->value])->all(),
            'tax_rates' => (array) config('gdo_trade_terms.tax_rates'),
            'tolerance_percent' => (string) config('gdo_trade_terms.tolerance_percent'),
            'customers' => $this->scoped('contacts', $tenantId)->whereIn('type', ['customer', 'both'])->orderBy('company_name')->limit(300)->get(['id', 'company_name', 'first_name', 'last_name', 'country'])->map(static fn ($row): array => ['id' => (string) $row->id, 'name' => $row->company_name ?: trim(($row->first_name ?? '').' '.($row->last_name ?? '')), 'country' => $row->country])->all(),
            'categories' => $this->scoped('product_categories', $tenantId)->orderBy('name')->limit(500)->get(['id', 'name', 'parent_id'])->map(static fn ($row): array => ['id' => (string) $row->id, 'name' => $row->name, 'parent_id' => $row->parent_id])->all(),
            'brands' => $this->scoped('product_brands', $tenantId)->orderBy('name')->limit(500)->get(['id', 'name'])->map(static fn ($row): array => ['id' => (string) $row->id, 'name' => $row->name])->all(),
            'accounts' => $this->scoped('chart_of_accounts', $tenantId)->where('is_active', true)->orderBy('account_code')->limit(1000)->get(['id', 'account_code', 'name'])->map(static fn ($row): array => ['id' => (string) $row->id, 'code' => $row->account_code, 'name' => $row->name])->all(),
        ];
    }

    /**
     * @return list<array{id: string, sku: string|null, name: string}>
     */
    public function products(string $tenantId, string $search): array
    {
        $needle = trim($search);
        $term = '%'.mb_strtolower($needle).'%';

        return $this->scoped('products', $tenantId)
            ->when($needle !== '', fn ($query) => $query->where(fn ($inner) => $inner->whereRaw('LOWER(name) LIKE ?', [$term])->orWhereRaw('LOWER(sku) LIKE ?', [$term])))
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'sku', 'name'])
            ->map(static fn ($row): array => ['id' => (string) $row->id, 'sku' => $row->sku, 'name' => (string) $row->name])
            ->all();
    }

    private function scoped(string $table, string $tenantId): Builder
    {
        return DB::table($table)->where('tenant_id', $tenantId)->whereNull('deleted_at');
    }
}
