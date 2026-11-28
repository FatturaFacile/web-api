<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Agricultural tax profile of the company for one country: the VAT regime of the producer and its compensation percentage overrides.
 *
 * @property string $country
 * @property string $regime
 * @property array<string, float|int|string>|null $compensation_overrides
 * @property string $annual_turnover
 */
final class TaxProfile extends BaseEnterpriseModel
{
    protected $table = 'bee_tax_profiles';

    protected function casts(): array
    {
        return [
            'compensation_overrides' => 'array',
            'annual_turnover' => 'decimal:2',
        ];
    }
}
