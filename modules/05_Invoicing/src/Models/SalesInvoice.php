<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectMilestone;

final class SalesInvoice extends BaseEnterpriseModel
{
    protected $table = 'invoices';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'due_date' => 'date',
            'posted_at' => 'datetime',
            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_net' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'total_gross' => 'decimal:2',
            'rounding_amount' => 'decimal:2',
            'total_paid' => 'decimal:2',
            'receivable_amount' => 'decimal:2',
            'split_payment' => 'boolean',
            'reverse_charge' => 'boolean',
            'withholding_tax_enabled' => 'boolean',
            'withholding_tax_percent' => 'decimal:2',
            'withholding_tax_amount' => 'decimal:2',
            'stamp_duty_applied' => 'boolean',
            'stamp_duty_amount' => 'decimal:2',
            'fund_enabled' => 'boolean',
            'fund_percent' => 'decimal:2',
            'fund_amount' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(SalesDeliveryNote::class, 'delivery_note_id');
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(CustomerAgreement::class, 'customer_agreement_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function projectMilestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'project_milestone_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesInvoiceItem::class, 'invoice_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CustomerLedgerEntry::class, 'invoice_id');
    }

    public function creditApplications(): HasMany
    {
        return $this->hasMany(InvoiceCreditApplication::class, 'credit_note_id');
    }

    public function appliedCredits(): HasMany
    {
        return $this->hasMany(InvoiceCreditApplication::class, 'original_invoice_id');
    }

    public function glJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'gl_journal_entry_id');
    }

    public function isCreditNote(): bool
    {
        return $this->invoice_type === 'credit_note';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted' || $this->posted_at !== null;
    }

    public function getOutstandingBalanceAttribute(): float
    {
        return max(0.0, (float) $this->total_gross - (float) $this->total_paid);
    }
}
