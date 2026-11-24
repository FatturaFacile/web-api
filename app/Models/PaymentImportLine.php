<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentImportLine extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'payment_import_id',
        'invoice_id',
        'payment_id',
        'entry_hash',
        'booking_date',
        'amount',
        'currency',
        'reference',
        'remittance_info',
        'debtor_name',
        'status',
        'note',
        'fee_amount',
        'reference_type',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'booking_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<PaymentImport, $this>
     */
    public function paymentImport(): BelongsTo
    {
        return $this->belongsTo(PaymentImport::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
