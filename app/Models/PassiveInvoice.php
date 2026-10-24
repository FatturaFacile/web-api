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

class PassiveInvoice extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $hidden = ['xml_payload'];

    protected $fillable = [
        'tenant_id',
        'supplier_id',
        'invoice_number',
        'date',
        'due_date',
        'total_amount',
        'currency',
        'sdi_lot_id',
        'is_reconciled',
        'xml_payload',
        'qr_raw_payload',
        'document_type',
        'total_net',
        'total_tax',
        'payment_status',
        'document_hash',
        'sdi_file_name',
        'total_paid',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'due_date' => 'date',
            'is_reconciled' => 'boolean',
            'total_amount' => 'decimal:2',
            'total_net' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'total_paid' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return HasMany<SupplierPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }
}
