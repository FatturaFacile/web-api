<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SupplierEvaluation extends BaseEnterpriseModel
{
    protected $table = 'supplier_evaluations';

    protected function casts(): array
    {
        return [
            'evaluation_period_start' => 'date',
            'evaluation_period_end' => 'date',
            'on_time_delivery_rate' => 'decimal:2',
            'quality_acceptance_rate' => 'decimal:2',
            'price_competitiveness_score' => 'decimal:2',
            'is_approved_vendor' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_user_id');
    }
}
