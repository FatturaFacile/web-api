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
use Modules\GeneralLedger\Services\Tax\Decimal;

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
        'gl_journal_entry_id',
        'exchange_rate',
        'withholding_tax_amount',
        'withholdings',
        'declaration_of_intent_id',
        'cost_account_id',
        'cost_account_source',
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
            'exchange_rate' => 'decimal:6',
            'withholding_tax_amount' => 'decimal:2',
            'withholdings' => 'array',
        ];
    }

    /**
     * What is paid to the supplier: the withholding goes to the tax authority or to the fund instead.
     */
    public function amountDue(): string
    {
        return Decimal::round2(Decimal::sub((string) ($this->total_amount ?? '0'), (string) ($this->withholding_tax_amount ?? '0')));
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return HasMany<PassiveInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PassiveInvoiceLine::class)->orderBy('line_number');
    }

    /**
     * @return HasMany<SupplierPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }
}
