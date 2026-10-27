<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransaction extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'bank_transactions';

    protected $fillable = [
        'tenant_id',
        'bank_account_id',
        'booking_date',
        'value_date',
        'amount',
        'currency',
        'description',
        'reference',
        'matched_invoice_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'booking_date' => 'date',
            'value_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function matchedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'matched_invoice_id');
    }
}
