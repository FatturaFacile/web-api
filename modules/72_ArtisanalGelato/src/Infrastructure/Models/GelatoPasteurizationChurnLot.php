<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Pasteurized base lot or churned flavor lot with HACCP readings and genealogy (parent_lot_id).
 */
final class GelatoPasteurizationChurnLot extends BaseEnterpriseModel
{
    protected $table = 'gelato_pasteurization_churn_lots';

    protected function casts(): array
    {
        return [
            'mix_weight_grams' => 'float',
            'mix_volume_liters' => 'float',
            'gelato_volume_liters' => 'float',
            'overrun_percent' => 'float',
            'yield_grams' => 'float',
            'yield_tubs' => 'integer',
            'haccp_readings' => 'array',
            'haccp_passed' => 'boolean',
            'haccp_findings' => 'array',
            'aged_since' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
