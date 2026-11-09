<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Models;

/**
 * Work of a subcontractor certified on a date, up to the contract amount.
 *
 * @property string $subcontract_id
 * @property ?string $site_cost_id
 * @property string $amount
 */
final class SubcontractCertification extends TradesRecord
{
    protected $table = 'ct_subcontract_certifications';

    protected function casts(): array
    {
        return ['certified_on' => 'date', 'amount' => 'decimal:2'];
    }
}
