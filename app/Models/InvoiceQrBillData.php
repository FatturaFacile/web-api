<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceQrBillData extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'invoice_qr_bill_data';

    protected $fillable = [
        'invoice_id',
        'qr_iban',
        'scor_reference',
        'creditor_info',
        'debtor_info',
        'qr_bill_svg',
    ];

    protected function casts(): array
    {
        return [
            'creditor_info' => 'array',
            'debtor_info' => 'array',
        ];
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function scorReference(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value !== null ? trim($value) : null,
        );
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
