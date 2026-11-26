<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Commands;

use Modules\Treasury\Currency\Domain\Enums\UnrealizedGainTreatment;
use Modules\Treasury\Currency\Domain\Enums\VatRateMethod;

/**
 * Changes the currency settings of a company. Deactivating a currency with open items or balances is refused (409).
 */
final readonly class UpdateCurrencySettingsCommand
{
    /**
     * @param  list<string>  $activeCurrencies
     */
    public function __construct(
        public string $tenantId,
        public array $activeCurrencies,
        public ?string $alertThresholdPercent,
        public UnrealizedGainTreatment $gainTreatment,
        public ?VatRateMethod $vatRateMethod = null,
        public ?string $gainAccountId = null,
        public ?string $lossAccountId = null,
        public ?string $gainProvisionAccountId = null,
        public ?string $actorId = null,
    ) {}
}
