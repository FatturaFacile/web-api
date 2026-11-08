<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Illuminate\Support\Facades\DB;
use Modules\Procurement\Models\ProcurementSetting;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Support\Decimal;

/**
 * Tolerances of the company (one row per tenant, created with zero tolerances on first read). A supplier may carry its
 * own over-receipt and price tolerance, which wins over the company one.
 */
final class ProcurementSettingsService
{
    /**
     * The settings of the tenant, created with the defaults when missing.
     */
    public function forTenant(string $tenantId): ProcurementSetting
    {
        $existing = ProcurementSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();

        if ($existing !== null) {
            return $existing;
        }

        return ProcurementSetting::withoutGlobalScopes()->firstOrCreate(['tenant_id' => $tenantId]);
    }

    /**
     * Updates the settings of the tenant.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(string $tenantId, array $data): ProcurementSetting
    {
        return DB::transaction(function () use ($tenantId, $data): ProcurementSetting {
            $settings = $this->forTenant($tenantId);
            $locked = ProcurementSetting::withoutGlobalScopes()->whereKey($settings->id)->lockForUpdate()->firstOrFail();
            $locked->fill($data)->save();

            return $locked;
        });
    }

    /**
     * Over-receipt tolerance in percent for the supplier.
     */
    public function overReceiptTolerance(string $tenantId, ?Supplier $supplier): string
    {
        if ($supplier?->over_receipt_tolerance_percent !== null) {
            return Decimal::of($supplier->over_receipt_tolerance_percent, 2);
        }

        return Decimal::of($this->forTenant($tenantId)->over_receipt_tolerance_percent, 2);
    }

    /**
     * Price tolerance (percent, absolute amount) for matching invoices of the supplier.
     *
     * @return array{percent: string, amount: string}
     */
    public function priceTolerance(string $tenantId, ?Supplier $supplier): array
    {
        $settings = $this->forTenant($tenantId);

        return [
            'percent' => Decimal::of($supplier->price_tolerance_percent ?? $settings->price_tolerance_percent, 2),
            'amount' => Decimal::of($settings->price_tolerance_amount, 2),
        ];
    }
}
