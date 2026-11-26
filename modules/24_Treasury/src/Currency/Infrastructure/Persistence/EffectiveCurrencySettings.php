<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Persistence;

use Modules\Treasury\Currency\Domain\Enums\UnrealizedGainTreatment;
use Modules\Treasury\Currency\Domain\Enums\VatRateMethod;

/**
 * The currency settings of a company with every default applied.
 */
final readonly class EffectiveCurrencySettings
{
    /**
     * @param  list<string>  $activeCurrencies  the functional currency is always among them
     */
    public function __construct(
        public string $tenantId,
        public string $country,
        public string $functionalCurrency,
        public array $activeCurrencies,
        public ?string $alertThresholdPercent,
        public UnrealizedGainTreatment $gainTreatment,
        public VatRateMethod $vatRateMethod,
        public ?string $gainAccountId,
        public ?string $lossAccountId,
        public ?string $gainProvisionAccountId,
    ) {}

    public function isActive(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->activeCurrencies, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'functional_currency' => $this->functionalCurrency,
            'active_currencies' => $this->activeCurrencies,
            'alert_threshold_percent' => $this->alertThresholdPercent,
            'unrealized_gain_treatment' => $this->gainTreatment->value,
            'vat_rate_method' => $this->vatRateMethod->value,
            'revaluation_accounts' => [
                'unrealized_gain' => $this->gainAccountId,
                'unrealized_loss' => $this->lossAccountId,
                'unrealized_gain_provision' => $this->gainProvisionAccountId,
            ],
        ];
    }
}
