<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Persistence;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\Treasury\Currency\Domain\Enums\UnrealizedGainTreatment;
use Modules\Treasury\Currency\Domain\Enums\VatRateMethod;

/**
 * The currency settings of a company: active currencies (the functional one always among them), the threshold of the daily
 * movement alert, the treatment of unrealised gains, the VAT rate method and the optional revaluation accounts.
 *
 * @property list<string> $active_currencies
 * @property string|null $alert_threshold_percent
 * @property UnrealizedGainTreatment $unrealized_gain_treatment
 * @property VatRateMethod|null $vat_rate_method
 * @property Carbon|null $vat_method_changed_at
 * @property string|null $unrealized_gain_account_id
 * @property string|null $unrealized_loss_account_id
 * @property string|null $unrealized_gain_provision_account_id
 */
final class CurrencySetting extends BaseEnterpriseModel
{
    protected $table = 'currency_settings';

    protected function casts(): array
    {
        return [
            'active_currencies' => 'array',
            'alert_threshold_percent' => 'decimal:2',
            'unrealized_gain_treatment' => UnrealizedGainTreatment::class,
            'vat_rate_method' => VatRateMethod::class,
            'vat_method_changed_at' => 'datetime',
        ];
    }
}
