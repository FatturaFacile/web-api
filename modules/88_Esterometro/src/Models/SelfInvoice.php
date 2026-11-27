<?php

declare(strict_types=1);

namespace Modules\Esterometro\Models;

use App\Models\Contact;
use App\Models\PassiveInvoice;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Esterometro\Domain\Enums\SdiTransmissionStatus;
use Modules\Esterometro\Domain\Enums\SelfInvoiceDocumentType;
use Modules\Esterometro\Domain\Enums\SelfInvoiceStatus;

/**
 * Self-invoice / integration document (Autofattura TD16–TD28) linked to a purchase invoice.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $passive_invoice_id
 * @property string|null $linked_fpa_document_id
 * @property string|null $supplier_id
 * @property SelfInvoiceDocumentType $document_type
 * @property SelfInvoiceDocumentType|null $suggested_document_type
 * @property bool $is_type_overridden
 * @property string|null $override_reason
 * @property array<int, array<string, mixed>>|null $compliance_warnings
 * @property SelfInvoiceStatus $status
 * @property SdiTransmissionStatus $sdi_status
 * @property string $document_number
 * @property int $fiscal_year
 * @property int $sequential_number
 * @property string $sectional_code
 * @property Carbon $integration_date
 * @property Carbon|null $operation_date
 * @property Carbon|null $receipt_date
 * @property Carbon $transmission_deadline_date
 * @property string|null $regularization_trigger
 * @property string $supplier_country_code
 * @property string $supplier_region
 * @property string $supplier_name
 * @property string|null $supplier_vat_number
 * @property string|null $supplier_fiscal_code
 * @property array<string, mixed>|null $supplier_address
 * @property string|null $original_invoice_number
 * @property Carbon|null $original_invoice_date
 * @property string|null $original_sdi_identifier
 * @property string $original_currency
 * @property string $original_net_amount
 * @property string $original_vat_amount
 * @property string $original_gross_amount
 * @property string $exchange_rate
 * @property Carbon|null $exchange_rate_date
 * @property string $exchange_rate_source
 * @property string $currency
 * @property string $taxable_amount_eur
 * @property string $vat_amount_eur
 * @property string $deductible_vat_eur
 * @property string $non_deductible_vat_eur
 * @property string $gross_amount_eur
 * @property string $soggetto_emittente
 * @property string $recipient_code
 * @property string|null $sdi_identificativo
 * @property string|null $sdi_filename
 * @property string|null $sdi_progressive
 * @property string|null $xml_content
 * @property string|null $xml_sha256
 * @property string|null $signed_storage_path
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_code
 * @property string|null $rejection_description
 * @property string|null $sales_vat_entry_id
 * @property string|null $purchase_vat_entry_id
 * @property string|null $journal_entry_id
 * @property bool $is_vat_reconciled
 * @property string|null $notes
 * @property string|null $created_by
 */
final class SelfInvoice extends BaseEnterpriseModel
{
    protected $table = 'self_invoices';

    protected $fillable = [
        'tenant_id',
        'passive_invoice_id',
        'linked_fpa_document_id',
        'supplier_id',
        'document_type',
        'suggested_document_type',
        'is_type_overridden',
        'override_reason',
        'compliance_warnings',
        'status',
        'sdi_status',
        'document_number',
        'fiscal_year',
        'sequential_number',
        'sectional_code',
        'integration_date',
        'operation_date',
        'receipt_date',
        'transmission_deadline_date',
        'regularization_trigger',
        'supplier_country_code',
        'supplier_region',
        'supplier_name',
        'supplier_vat_number',
        'supplier_fiscal_code',
        'supplier_address',
        'original_invoice_number',
        'original_invoice_date',
        'original_sdi_identifier',
        'original_currency',
        'original_net_amount',
        'original_vat_amount',
        'original_gross_amount',
        'exchange_rate',
        'exchange_rate_date',
        'exchange_rate_source',
        'currency',
        'taxable_amount_eur',
        'vat_amount_eur',
        'deductible_vat_eur',
        'non_deductible_vat_eur',
        'gross_amount_eur',
        'soggetto_emittente',
        'recipient_code',
        'sdi_identificativo',
        'sdi_filename',
        'sdi_progressive',
        'xml_content',
        'xml_sha256',
        'signed_storage_path',
        'sent_at',
        'delivered_at',
        'rejected_at',
        'rejection_code',
        'rejection_description',
        'sales_vat_entry_id',
        'purchase_vat_entry_id',
        'journal_entry_id',
        'is_vat_reconciled',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => SelfInvoiceDocumentType::class,
            'suggested_document_type' => SelfInvoiceDocumentType::class,
            'is_type_overridden' => 'boolean',
            'compliance_warnings' => 'array',
            'status' => SelfInvoiceStatus::class,
            'sdi_status' => SdiTransmissionStatus::class,
            'fiscal_year' => 'integer',
            'sequential_number' => 'integer',
            'integration_date' => 'date',
            'operation_date' => 'date',
            'receipt_date' => 'date',
            'transmission_deadline_date' => 'date',
            'supplier_address' => 'array',
            'original_invoice_date' => 'date',
            'original_net_amount' => 'decimal:2',
            'original_vat_amount' => 'decimal:2',
            'original_gross_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'exchange_rate_date' => 'date',
            'taxable_amount_eur' => 'decimal:2',
            'vat_amount_eur' => 'decimal:2',
            'deductible_vat_eur' => 'decimal:2',
            'non_deductible_vat_eur' => 'decimal:2',
            'gross_amount_eur' => 'decimal:2',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'rejected_at' => 'datetime',
            'is_vat_reconciled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PassiveInvoice, $this>
     */
    public function passiveInvoice(): BelongsTo
    {
        return $this->belongsTo(PassiveInvoice::class, 'passive_invoice_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    /**
     * @return HasMany<SelfInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SelfInvoiceLine::class, 'self_invoice_id')->orderBy('line_number');
    }

    /**
     * @return HasMany<SelfInvoiceSdiLog, $this>
     */
    public function sdiLogs(): HasMany
    {
        return $this->hasMany(SelfInvoiceSdiLog::class, 'self_invoice_id')->orderByDesc('created_at');
    }
}
