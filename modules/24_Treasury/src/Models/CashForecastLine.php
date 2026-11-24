<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CashForecastLine extends BaseEnterpriseModel
{
    protected $table = 'cash_forecast_lines';

    protected function casts(): array
    {
        return [
            'projected_date' => 'date',
            'confidence_weight' => 'decimal:2',
            'expected_amount' => 'decimal:2',
            'weighted_amount' => 'decimal:2',
        ];
    }

    public function cashForecast(): BelongsTo
    {
        return $this->belongsTo(CashForecast::class, 'cash_forecast_id');
    }
}
