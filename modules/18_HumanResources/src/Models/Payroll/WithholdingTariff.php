<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models\Payroll;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cantonal withholding tax (Quellensteuer) tariff, e.g. canton TI, code A0N, valid from 2026-01-01.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $canton
 * @property string $tariff_code
 * @property \Illuminate\Support\Carbon $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_to
 * @property string $source
 * @property string|null $description
 */
final class WithholdingTariff extends BaseEnterpriseModel
{
    protected $table = 'hr_ch_withholding_tariffs';

    public function isSharedLookup(): bool
    {
        return true;
    }

    public function isSystemDefault(): bool
    {
        return $this->tenant_id === null;
    }

    /**
     * @return HasMany<WithholdingTariffBracket, $this>
     */
    public function brackets(): HasMany
    {
        return $this->hasMany(WithholdingTariffBracket::class, 'withholding_tariff_id')->orderBy('income_from_rappen');
    }

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }
}
