<?php

declare(strict_types=1);

namespace App\Services\Invoicing\Tax;

use App\Models\Tenant;

/**
 * Fiscal preferences of a tenant (`settings.preferences.fiscal`), with the legacy `settings.regime_fiscale` key as a
 * fallback for the fiscal regime.
 */
final readonly class TenantFiscalSettings
{
    /**
     * @param  array<string, mixed>  $preferences
     */
    public function __construct(
        public string $regimeFiscale,
        private array $preferences,
    ) {}

    public static function fromTenant(?Tenant $tenant): self
    {
        $settings = is_array($tenant?->settings) ? $tenant->settings : [];
        $preferences = is_array($settings['preferences']['fiscal'] ?? null) ? $settings['preferences']['fiscal'] : [];
        $regime = $preferences['regime_fiscale'] ?? $settings['regime_fiscale'] ?? 'RF01';

        return new self(is_string($regime) && $regime !== '' ? strtoupper($regime) : 'RF01', $preferences);
    }

    public function isForfettario(): bool
    {
        return $this->regimeFiscale === (string) config('italian_tax.forfettario.regime', 'RF19');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function defaultFund(): ?array
    {
        $fund = $this->preferences['default_fund'] ?? null;

        return is_array($fund) ? $fund : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function defaultWithholding(): ?array
    {
        $withholding = $this->preferences['default_withholding'] ?? null;

        return is_array($withholding) ? $withholding : null;
    }

    public function stampDutyChargedToCustomer(): bool
    {
        $mode = $this->preferences['stamp_duty_charge_mode'] ?? config('italian_tax.stamp_duty.default_charge_mode', 'customer');

        return $mode !== 'issuer';
    }

    public function enasarcoEnabled(): bool
    {
        return (bool) ($this->preferences['enasarco_enabled'] ?? false);
    }
}
