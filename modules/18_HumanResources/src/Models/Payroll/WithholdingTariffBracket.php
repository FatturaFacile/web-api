<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models\Payroll;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Monthly income bracket of a withholding tax tariff. Amounts in Rappen, rate in parts per million.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $withholding_tariff_id
 * @property int $income_from_rappen
 * @property int|null $income_to_rappen
 * @property int $rate_ppm
 * @property int $fixed_amount_rappen
 */
final class WithholdingTariffBracket extends BaseEnterpriseModel
{
    protected $table = 'hr_ch_withholding_tariff_brackets';

    public function isSharedLookup(): bool
    {
        return true;
    }

    /**
     * @return BelongsTo<WithholdingTariff, $this>
     */
    public function tariff(): BelongsTo
    {
        return $this->belongsTo(WithholdingTariff::class, 'withholding_tariff_id');
    }

    protected function casts(): array
    {
        return [
            'income_from_rappen' => 'integer',
            'income_to_rappen' => 'integer',
            'rate_ppm' => 'integer',
            'fixed_amount_rappen' => 'integer',
        ];
    }
}
