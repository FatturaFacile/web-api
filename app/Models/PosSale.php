<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PosSale extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'pos_session_id',
        'receipt_number',
        'total_net',
        'total_tax',
        'total_gross',
        'payment_method',
        'contact_id',
        'loyalty_card_id',
        'cash_amount',
        'card_amount',
        'status',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'card_amount' => 'decimal:2',
            'cash_amount' => 'decimal:2',
            'refunded_at' => 'datetime',
            'total_gross' => 'decimal:2',
            'total_net' => 'decimal:2',
            'total_tax' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PosSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    /**
     * @return HasMany<PosSaleItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PosSaleItem::class);
    }

    /**
     * @return BelongsTo<LoyaltyCard, $this>
     */
    public function loyaltyCard(): BelongsTo
    {
        return $this->belongsTo(LoyaltyCard::class);
    }
}
