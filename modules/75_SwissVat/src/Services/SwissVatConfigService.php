<?php

declare(strict_types=1);

namespace Modules\SwissVat\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\SwissVat\Domain\Enums\SwissVatExemptionType;
use Modules\SwissVat\Domain\Enums\SwissVatMethod;
use Modules\SwissVat\Domain\ValueObjects\SwissUidMwst;
use Modules\SwissVat\Exceptions\SwissVatException;
use Modules\SwissVat\Models\SwissVatConfig;
use Modules\SwissVat\Models\SwissVatTaxCode;

/**
 * Manages tenant Swiss VAT configuration and effective-dated custom tax codes.
 */
final class SwissVatConfigService
{
    public function getOrDefault(string $tenantId): SwissVatConfig
    {
        $existing = SwissVatConfig::query()->where('tenant_id', $tenantId)->first();

        if ($existing !== null) {
            return $existing;
        }

        return SwissVatConfig::create([
            'tenant_id' => $tenantId,
            'accounting_method' => SwissVatMethod::Effective->value,
            'consideration_basis' => 'agreed',
            'reporting_frequency' => 'quarterly',
            'rounding_mode' => 'chf_005',
            'auto_detect_bezugsteuer' => true,
            'bezugsteuer_threshold_chf' => '10000.00',
            'default_currency' => 'CHF',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsert(string $tenantId, ?string $userId, array $data): SwissVatConfig
    {
        $config = $this->getOrDefault($tenantId);

        if (isset($data['uid_mwst']) && is_string($data['uid_mwst']) && trim($data['uid_mwst']) !== '') {
            $parsed = SwissUidMwst::parse($data['uid_mwst']);
            $data['uid_mwst'] = $parsed->formatted;
        }

        if (($data['accounting_method'] ?? $config->accounting_method) === SwissVatMethod::Saldosteuersatz->value) {
            $primary = $data['sss_rate_primary'] ?? $config->sss_rate_primary;
            if ($primary === null || ! SwissVatDecimal::isPositive((string) $primary)) {
                throw SwissVatException::invalid('sss_primary_rate_required');
            }
        }

        $config->fill($data);
        $config->updated_by = $userId;
        $config->save();

        return $config;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createTaxCode(string $tenantId, ?string $userId, array $data): SwissVatTaxCode
    {
        $exemption = (string) ($data['exemption_type'] ?? SwissVatExemptionType::None->value);

        return SwissVatTaxCode::create([
            'tenant_id' => $tenantId,
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => (string) $data['name'],
            'description' => $data['description'] ?? null,
            'rate_type' => (string) $data['rate_type'],
            'exemption_type' => $exemption,
            'rate_percent' => SwissVatDecimal::round2((string) $data['rate_percent']),
            'afc_box' => (string) $data['afc_box'],
            'is_bezugsteuer' => (bool) ($data['is_bezugsteuer'] ?? false),
            'is_input_tax_deductible' => $exemption !== SwissVatExemptionType::ExemptWithoutDeduction->value && $exemption !== SwissVatExemptionType::OutOfScope->value
                ? (bool) ($data['is_input_tax_deductible'] ?? true)
                : false,
            'sss_sector_code' => $data['sss_sector_code'] ?? null,
            'effective_from' => (string) ($data['effective_from'] ?? '2024-01-01'),
            'effective_to' => $data['effective_to'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by' => $userId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTaxCode(string $tenantId, string $id, ?string $userId, array $data): SwissVatTaxCode
    {
        $taxCode = SwissVatTaxCode::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($id)
            ->first();

        if ($taxCode === null) {
            throw SwissVatException::notFound('tax_code_not_found');
        }

        $taxCode->fill($data);
        $taxCode->updated_by = $userId;
        $taxCode->save();

        return $taxCode;
    }

    /**
     * @return Collection<int, SwissVatTaxCode>
     */
    public function listTaxCodes(string $tenantId): Collection
    {
        return SwissVatTaxCode::query()
            ->where(static function ($q) use ($tenantId): void {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            })
            ->orderByDesc('effective_from')
            ->orderBy('code')
            ->get();
    }
}
