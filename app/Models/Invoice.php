<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasFiscalAmounts;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Invoicing\Models\DeclarationOfIntent;

class Invoice extends Model
{
    use BelongsToTenant, HasFactory, HasFiscalAmounts, HasUuids, SoftDeletes;

    protected $fillable = [
        'contact_id',
        'invoice_type',
        'sdi_excluded',
        'invoice_number',
        'date',
        'due_date',
        'currency',
        'exchange_rate',
        'total_net',
        'total_tax',
        'total_gross',
        'rounding_amount',
        'payment_status',
        'jurisdiction',
        'split_payment',
        'reverse_charge',
        'notes',
        'fiscal_year',
        'sequential_number',
        'total_paid',
        'proforma_id',
        'sales_order_id',
        'delivery_note_id',
        'subscription_id',
        'quote_id',
        'is_deposit',
        'related_invoice_id',
        'payment_method',
        'payment_terms',
        'bank_name',
        'bank_iban',
        'vat_payability',
        'withholding_tax_enabled',
        'withholding_tax_percent',
        'withholding_tax_amount',
        'stamp_duty_applied',
        'stamp_duty_amount',
        'fund_enabled',
        'fund_type',
        'fund_percent',
        'fund_amount',
        'document_type',
        'vat_nature',
        'source_passive_invoice_id',
        'linked_document_number',
        'linked_document_date',
        'declaration_of_intent_id',
        'skip_declaration_of_intent',
        'withholding_tax_type',
        'withholding_tax_reason',
        'withholding_tax_base_percent',
        'stamp_duty_charged',
        'fund_vat_rate',
        'fund_vat_amount',
        'fund_subject_to_withholding',
        'enasarco_enabled',
        'enasarco_agent_type',
        'enasarco_base',
        'enasarco_percent',
        'enasarco_amount',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'due_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'total_net' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'total_gross' => 'decimal:2',
            'rounding_amount' => 'decimal:2',
            'split_payment' => 'boolean',
            'sdi_excluded' => 'boolean',
            'reverse_charge' => 'boolean',
            'total_paid' => 'decimal:2',
            'is_deposit' => 'boolean',
            'exchange_rate_date' => 'date',
            'withholding_tax_enabled' => 'boolean',
            'withholding_tax_percent' => 'decimal:2',
            'withholding_tax_amount' => 'decimal:2',
            'stamp_duty_applied' => 'boolean',
            'stamp_duty_amount' => 'decimal:2',
            'fund_enabled' => 'boolean',
            'fund_percent' => 'decimal:2',
            'fund_amount' => 'decimal:2',
            'fund_vat_rate' => 'decimal:2',
            'fund_vat_amount' => 'decimal:2',
            'fund_subject_to_withholding' => 'boolean',
            'skip_declaration_of_intent' => 'boolean',
            'linked_document_date' => 'date',
            'withholding_tax_base_percent' => 'decimal:2',
            'stamp_duty_charged' => 'boolean',
            'enasarco_enabled' => 'boolean',
            'enasarco_base' => 'decimal:2',
            'enasarco_percent' => 'decimal:2',
            'enasarco_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<DeclarationOfIntent, $this>
     */
    public function declarationOfIntent(): BelongsTo
    {
        return $this->belongsTo(DeclarationOfIntent::class, 'declaration_of_intent_id');
    }

    /**
     * @return BelongsTo<PassiveInvoice, $this>
     */
    public function sourcePassiveInvoice(): BelongsTo
    {
        return $this->belongsTo(PassiveInvoice::class, 'source_passive_invoice_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function relatedInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_invoice_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('id');
    }

    /**
     * @return HasOne<InvoiceSdiMetadata, $this>
     */
    public function sdiMetadata(): HasOne
    {
        return $this->hasOne(InvoiceSdiMetadata::class);
    }

    /**
     * @return HasOne<InvoiceQrBillData, $this>
     */
    public function qrBillData(): HasOne
    {
        return $this->hasOne(InvoiceQrBillData::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function proforma(): BelongsTo
    {
        return $this->belongsTo(self::class, 'proforma_id');
    }

    /**
     * @return BelongsTo<SalesOrder, $this>
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /**
     * @return BelongsTo<DeliveryNote, $this>
     */
    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    /**
     * @return HasMany<PaymentReminder, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(PaymentReminder::class);
    }

    /**
     * @return HasMany<SdiUuidLink, $this>
     */
    public function sdiUuidLinks(): HasMany
    {
        return $this->hasMany(SdiUuidLink::class);
    }
}
