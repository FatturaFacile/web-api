<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Persistence;

use App\Models\Tenant;
use Modules\GeneralLedger\Models\LedgerSetting;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Treasury\Currency\Domain\Enums\UnrealizedGainTreatment;
use Modules\Treasury\Currency\Domain\Enums\VatRateMethod;
use Modules\Treasury\Currency\Domain\Policies\FxVatPolicyResolver;

/**
 * Reads the currency settings of a company, applying the defaults: the functional currency comes from the company (Mod 001),
 * the active currencies default to the configured list plus the functional one, unrealised gains follow the General Ledger
 * choice (Swiss companies book them to a provision by default, CO Art. 960a), the VAT method follows the country.
 */
final readonly class CurrencySettingsRepository
{
    public function __construct(private FxVatPolicyResolver $vatPolicies) {}

    public function find(string $tenantId): ?CurrencySetting
    {
        return CurrencySetting::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
    }

    public function effective(string $tenantId): EffectiveCurrencySettings
    {
        $setting = $this->find($tenantId);
        $country = strtoupper((string) (Tenant::query()->whereKey($tenantId)->value('country') ?? 'IT'));
        $functional = TenantAccountingCurrency::of($tenantId);

        $active = $setting === null ? (array) config('currency_fx.default_active_currencies', []) : $setting->active_currencies;
        $active = array_values(array_unique(array_map('strtoupper', [...$active, $functional])));
        sort($active);

        $policy = $this->vatPolicies->forCountry($country);
        $vatMethod = $setting->vat_rate_method ?? ($country === 'CH' || $country === 'LI' ? VatRateMethod::EstvMonthly : VatRateMethod::Ecb);

        return new EffectiveCurrencySettings(
            tenantId: $tenantId,
            country: $country,
            functionalCurrency: $functional,
            activeCurrencies: $active,
            alertThresholdPercent: $setting === null ? '2.00' : $setting->alert_threshold_percent,
            gainTreatment: $setting->unrealized_gain_treatment ?? $this->defaultGainTreatment($tenantId, $country),
            vatRateMethod: in_array($vatMethod, $policy->allowedMethods(), true) ? $vatMethod : $policy->allowedMethods()[0],
            gainAccountId: $setting?->unrealized_gain_account_id,
            lossAccountId: $setting?->unrealized_loss_account_id,
            gainProvisionAccountId: $setting?->unrealized_gain_provision_account_id,
        );
    }

    private function defaultGainTreatment(string $tenantId, string $country): UnrealizedGainTreatment
    {
        $ledger = LedgerSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')->first();

        if ($ledger !== null && $ledger->enforce_swiss_imparity_principle === false) {
            return UnrealizedGainTreatment::Recognise;
        }

        $configured = $ledger === null ? null : UnrealizedGainTreatment::tryFrom((string) $ledger->unrealised_gain_treatment);

        return $configured ?? ($country === 'CH' ? UnrealizedGainTreatment::Provision : UnrealizedGainTreatment::Recognise);
    }
}
