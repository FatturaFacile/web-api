<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'expenses';

    protected $fillable = [
        'tenant_id',
        'expense_number',
        'category_id',
        'date',
        'amount_net',
        'tax_rate',
        'tax_amount',
        'amount_gross',
        'currency',
        'merchant_name',
        'payment_method',
        'status',
        'is_billable',
        'is_reimbursable',
        'notes',
        'accounting_exported_at',
        'contact_id',
        'user_id',
        'project_id',
        'ticket_id',
        'service_report_id',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount_net' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'amount_gross' => 'decimal:2',
            'is_billable' => 'boolean',
            'is_reimbursable' => 'boolean',
            'accounting_exported_at' => 'datetime',
        ];
    }

    /**
     * Category from the shared lookup engine.
     *
     * @return BelongsTo<LookupItem, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(LookupItem::class, 'category_id');
    }

    /**
     * Supplier or merchant contact.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * User/technician who incurred the expense.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Optional linked service ticket.
     *
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    /**
     * Optional linked service report.
     *
     * @return BelongsTo<ServiceReport, $this>
     */
    public function serviceReport(): BelongsTo
    {
        return $this->belongsTo(ServiceReport::class, 'service_report_id');
    }

    /**
     * Dedicated expense receipts and attachments.
     *
     * @return HasMany<ExpenseAttachment, $this>
     */
    public function expenseAttachments(): HasMany
    {
        return $this->hasMany(ExpenseAttachment::class, 'expense_id');
    }

    /**
     * Polymorphic integration with the platform Attachment engine.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Creator user.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Check if the expense has already been exported to the accountant.
     */
    public function isExported(): bool
    {
        return $this->accounting_exported_at !== null;
    }
}
