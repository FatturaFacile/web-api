<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTermInstallment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'payment_term_id',
        'sequence',
        'percent',
        'days',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'percent' => 'decimal:2',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PaymentTerm, $this>
     */
    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }
}
