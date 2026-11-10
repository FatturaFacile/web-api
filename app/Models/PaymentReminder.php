<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReminder extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'invoice_id',
        'level',
        'channel',
        'recipient',
        'amount_due',
        'dunning_fee',
        'interest_rate_percent',
        'interest_amount',
        'total_claimed_amount',
        'qr_bill_attached',
        'qr_reference',
        'status',
        'error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_due' => 'decimal:2',
            'dunning_fee' => 'decimal:2',
            'interest_rate_percent' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'total_claimed_amount' => 'decimal:2',
            'qr_bill_attached' => 'boolean',
            'level' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
