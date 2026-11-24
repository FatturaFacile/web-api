<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesOpportunity extends BaseEnterpriseModel
{
    protected $table = 'sales_opportunities';

    protected function casts(): array
    {
        return [
            'estimated_amount' => 'decimal:2',
            'probability_percent' => 'decimal:2',
            'expected_close_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }
}
