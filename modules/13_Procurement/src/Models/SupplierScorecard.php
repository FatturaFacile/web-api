<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SupplierScorecard extends BaseEnterpriseModel
{
    protected $table = 'supplier_scorecards';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'quarter' => 'integer',
            'otif_percentage' => 'decimal:2',
            'defect_ppm' => 'integer',
            'cost_savings_amount' => 'decimal:2',
            'compliance_score' => 'decimal:2',
            'is_avl_active' => 'boolean',
            'scorecard_data' => 'array',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
