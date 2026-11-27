<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Services;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Modules\GreenCare\Domain\Compliance\ComplianceStrategyResolver;
use Modules\GreenCare\Domain\Enums\BillingMode;
use Modules\GreenCare\Domain\Enums\ContractStatus;
use Modules\GreenCare\Domain\Enums\InstallmentFrequency;
use Modules\GreenCare\Domain\Exceptions\GreenCareException;
use Modules\GreenCare\Models\GreenSpaceSite;
use Modules\GreenCare\Models\SeasonalMaintenanceContract;

/**
 * Creates and changes the seasonal contracts: the number, the jurisdiction (the one of the site), the VAT rate of the jurisdiction and the
 * withholding of the Italian condominiums, which is switched on by itself for a condominium unless the user states otherwise.
 */
final class ContractWriter
{
    public function __construct(
        private readonly DocumentNumbering $numbering,
        private readonly ComplianceStrategyResolver $strategies,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request
     */
    public function create(array $data, string $tenantId): SeasonalMaintenanceContract
    {
        $attributes = $this->attributes($data);
        $attributes['tenant_id'] = $tenantId;
        $attributes['status'] = ContractStatus::Draft->value;
        $attributes['contract_number'] = ($data['contract_number'] ?? null) ?: $this->numbering->nextContractNumber(Carbon::parse((string) $data['season_start_date']));

        return SeasonalMaintenanceContract::query()->create($attributes)->refresh();
    }

    /**
     * @param  array<string, mixed>  $data  validated request
     */
    public function update(SeasonalMaintenanceContract $contract, array $data): SeasonalMaintenanceContract
    {
        $attributes = $this->attributes($data);

        if (! empty($data['contract_number'])) {
            $attributes['contract_number'] = $data['contract_number'];
        }

        $contract->update($attributes);

        return $contract->refresh();
    }

    /**
     * @throws GreenCareException contract_invalid_transition
     */
    public function activate(SeasonalMaintenanceContract $contract): SeasonalMaintenanceContract
    {
        if (! in_array($contract->status, [ContractStatus::Draft, ContractStatus::Suspended], true)) {
            throw GreenCareException::because('contract_invalid_transition', ['status' => $contract->status->value]);
        }

        $contract->update(['status' => ContractStatus::Active->value]);

        return $contract->refresh();
    }

    /**
     * @throws GreenCareException contract_invalid_transition
     */
    public function suspend(SeasonalMaintenanceContract $contract): SeasonalMaintenanceContract
    {
        if ($contract->status !== ContractStatus::Active) {
            throw GreenCareException::because('contract_invalid_transition', ['status' => $contract->status->value]);
        }

        $contract->update(['status' => ContractStatus::Suspended->value]);

        return $contract->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $site = GreenSpaceSite::query()->findOrFail((string) $data['site_id']);
        $jurisdiction = $site->jurisdiction;
        $isCondominium = (bool) ($data['is_condominium'] ?? false);
        $withholdingApplies = $jurisdiction === 'IT' && $this->strategies->for('IT')->condominiumWithholding() !== null;

        return [
            'site_id' => $site->id,
            'customer_id' => $data['customer_id'],
            'condominium_administrator_id' => $data['condominium_administrator_id'] ?? $site->condominium_administrator_id,
            'season_start_date' => $data['season_start_date'],
            'season_end_date' => $data['season_end_date'],
            'jurisdiction' => $jurisdiction,
            'billing_mode' => $data['billing_mode'] ?? BillingMode::FixedInstallments->value,
            'included_passages_count' => (int) ($data['included_passages_count'] ?? 0),
            'extra_passage_unit_price' => $data['extra_passage_unit_price'] ?? 0,
            'fixed_fee_amount' => $data['fixed_fee_amount'] ?? 0,
            'installment_frequency' => $data['installment_frequency'] ?? InstallmentFrequency::Monthly->value,
            'time_and_material_hourly_rate' => $data['time_and_material_hourly_rate'] ?? 0,
            'snow_removal_standby_fee' => $data['snow_removal_standby_fee'] ?? null,
            'snow_trigger_threshold_cm' => $data['snow_trigger_threshold_cm'] ?? null,
            'included_services' => array_values($data['included_services'] ?? []),
            'auto_bill_extra_passages' => (bool) ($data['auto_bill_extra_passages'] ?? true),
            'is_condominium' => $isCondominium,
            'condominium_fiscal_code' => $isCondominium ? strtoupper(trim((string) ($data['condominium_fiscal_code'] ?? ''))) : null,
            // An Italian condominium withholds 4%: the flag is on by itself and stays under the control of the user.
            'apply_condominium_withholding' => array_key_exists('apply_condominium_withholding', $data)
                ? (bool) $data['apply_condominium_withholding'] && $withholdingApplies
                : $isCondominium && $withholdingApplies,
            'vat_rate' => $data['vat_rate'] ?? $this->defaultVatRate($jurisdiction),
        ];
    }

    private function defaultVatRate(string $jurisdiction): float
    {
        return (float) config('green_care.vat_rates.'.$jurisdiction, 22.0);
    }

    public function tenantJurisdiction(string $tenantId): string
    {
        return $this->strategies->normalize(Tenant::query()->find($tenantId)->country ?? 'IT');
    }
}
