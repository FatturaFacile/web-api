<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Services;

use App\Models\Contact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\IndustrialLaundry\Domain\Enums\BillingModel;
use Modules\IndustrialLaundry\Exceptions\IndustrialLaundryException;
use Modules\IndustrialLaundry\Models\ParContract;
use Modules\IndustrialLaundry\Models\ParContractLine;

/**
 * Customer linen par stock contracts with their article lines.
 */
final class ParContractService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function save(string $tenantId, array $data, ?string $userId, ?ParContract $contract = null): ParContract
    {
        $customerId = (string) ($data['customer_id'] ?? $contract?->customer_id);

        if (! Contact::query()->whereKey($customerId)->exists()) {
            throw new IndustrialLaundryException('contact_not_found');
        }

        $validFrom = Carbon::parse((string) ($data['valid_from'] ?? $contract?->valid_from));
        $validTo = isset($data['valid_to']) ? Carbon::parse((string) $data['valid_to']) : ($contract?->valid_to);

        if ($validTo !== null && $validTo->lessThan($validFrom)) {
            throw new IndustrialLaundryException('period_invalid');
        }

        $lines = $data['lines'] ?? null;

        if (is_array($lines)) {
            $this->assertLines($lines);
        }

        $isActive = (bool) ($data['is_active'] ?? $contract->is_active ?? true);

        if ($isActive) {
            $this->assertNoOverlap($customerId, $validFrom, $validTo, $contract?->id);
        }

        return DB::transaction(function () use ($tenantId, $data, $userId, $contract, $customerId, $isActive, $lines): ParContract {
            $attributes = $this->attributes($data) + ['customer_id' => $customerId, 'is_active' => $isActive];
            if ($contract === null) {
                $attributes['tax_rate'] ??= (string) config('industrial_laundry.tax_rates.'.(string) ($attributes['jurisdiction'] ?? 'IT'), '22');
                $contract = ParContract::query()->create($attributes + ['tenant_id' => $tenantId, 'created_by' => $userId]);
            } else {
                $contract->forceFill($attributes + ['updated_by' => $userId])->save();
            }

            if (is_array($lines)) {
                ParContractLine::query()->where('contract_id', $contract->id)->get()->each->forceDelete();

                foreach ($lines as $line) {
                    ParContractLine::query()->create($this->lineAttributes($line) + ['tenant_id' => $tenantId, 'contract_id' => $contract->id, 'created_by' => $userId]);
                }
            }

            return $contract->refresh()->load('lines');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = [];

        foreach (['code', 'customer_segment', 'valid_from', 'valid_to', 'billing_model', 'kg_basis', 'unreturned_garment_replacement_price', 'unreturned_threshold_days', 'jurisdiction', 'currency', 'tax_rate', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (isset($attributes['billing_model']) && BillingModel::tryFrom((string) $attributes['billing_model']) === null) {
            throw new IndustrialLaundryException('billing_model_invalid');
        }

        if (isset($attributes['currency'])) {
            $attributes['currency'] = strtoupper((string) $attributes['currency']);
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function lineAttributes(array $line): array
    {
        $attributes = [];

        foreach (['article_type', 'product_id', 'label', 'circulating_stock_qty', 'weekly_min_billing_percentage', 'seasonal_min_billing_percentage', 'season_start_month', 'season_end_month', 'unit_wash_price', 'price_per_kg', 'rental_price', 'replacement_price'] as $field) {
            if (array_key_exists($field, $line)) {
                $attributes[$field] = $line[$field];
            }
        }

        return $attributes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function assertLines(array $lines): void
    {
        $types = array_column($lines, 'article_type');

        if (count($types) !== count(array_unique($types))) {
            throw new IndustrialLaundryException('duplicate_article_line');
        }

        foreach ($lines as $line) {
            $hasSeason = isset($line['season_start_month']) || isset($line['season_end_month']);

            if ($hasSeason && (! isset($line['season_start_month'], $line['season_end_month']) || ! isset($line['seasonal_min_billing_percentage']))) {
                throw new IndustrialLaundryException('season_incomplete');
            }
        }
    }

    private function assertNoOverlap(string $customerId, Carbon $from, ?Carbon $to, ?string $ignoreId): void
    {
        $overlap = ParContract::query()
            ->where('customer_id', $customerId)
            ->where('is_active', true)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->whereDate('valid_from', '<=', ($to ?? Carbon::create(9999, 12, 31))->toDateString())
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $from->toDateString()))
            ->exists();

        if ($overlap) {
            throw new IndustrialLaundryException('contract_overlap');
        }
    }
}
