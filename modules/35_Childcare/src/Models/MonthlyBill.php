<?php

declare(strict_types=1);

namespace Modules\Childcare\Models;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The fees of a child for a month, net of the subsidy, and the invoice that bills them.
 *
 * @property string $child_id
 * @property string $period
 * @property int $attended_days
 * @property string $gross_amount
 * @property string $subsidy_amount
 * @property string $net_amount
 * @property ?string $subsidy_rule_id
 * @property ?string $invoice_id
 */
final class MonthlyBill extends ChildcareRecord
{
    protected $table = 'cc_monthly_bills';

    /** @var list<string> */
    protected $hidden = ['child', 'invoice'];

    protected function casts(): array
    {
        return ['attended_days' => 'integer', 'meals' => 'integer', 'gross_amount' => 'decimal:2', 'subsidy_amount' => 'decimal:2', 'net_amount' => 'decimal:2'];
    }

    /**
     * Calculated grid columns (portable SQL): the bill status follows its invoice (draft = not invoiced yet).
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            'status' => "CASE WHEN cc_monthly_bills.invoice_id IS NULL THEN 'draft' WHEN (SELECT invoices.status FROM invoices WHERE invoices.id = cc_monthly_bills.invoice_id) = 'paid' THEN 'paid' ELSE 'issued' END",
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /**
     * @return Attribute<string, never>
     */
    protected function status(): Attribute
    {
        return Attribute::get(fn (): string => $this->invoice_id === null ? 'draft' : ($this->invoice?->status === 'paid' ? 'paid' : 'issued'));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function currency(): Attribute
    {
        return Attribute::get(fn (): string => $this->invoice?->currency ?? 'EUR');
    }

    /**
     * @return Attribute<?string, never>
     */
    protected function contactId(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->child?->billing_contact_id);
    }

    /**
     * @return BelongsTo<Child, $this>
     */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class, 'child_id');
    }

    /**
     * Name of the child, computed in PHP because the names are encrypted at rest.
     *
     * @return Attribute<?string, never>
     */
    protected function childName(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->child?->display_name);
    }

    /**
     * Name of the billing contact of the child.
     *
     * @return Attribute<?string, never>
     */
    protected function contactName(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->child?->billing_contact_name);
    }
}
