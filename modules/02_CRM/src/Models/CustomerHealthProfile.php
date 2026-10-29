<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CustomerHealthProfile extends BaseEnterpriseModel
{
    protected $table = 'crm_customer_health_profiles';

    protected function casts(): array
    {
        return [
            'activity_score' => 'decimal:2',
            'engagement_score' => 'decimal:2',
            'revenue_score' => 'decimal:2',
            'risk_score' => 'decimal:2',
            'health_index' => 'decimal:2',
            'last_interaction_at' => 'datetime',
            'last_invoiced_at' => 'datetime',
            'open_receivable_amount' => 'decimal:2',
            'overdue_receivable_amount' => 'decimal:2',
            'evaluation_metrics' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
