<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Handlers;

use Modules\Treasury\Currency\Application\Queries\GetCurrencySettingsQuery;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencySettingsRepository;
use Modules\Treasury\Currency\Infrastructure\Persistence\EffectiveCurrencySettings;

final readonly class GetCurrencySettingsHandler
{
    public function __construct(private CurrencySettingsRepository $settings) {}

    public function handle(GetCurrencySettingsQuery $query): EffectiveCurrencySettings
    {
        return $this->settings->effective($query->tenantId);
    }
}
