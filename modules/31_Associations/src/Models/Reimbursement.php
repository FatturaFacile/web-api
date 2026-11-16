<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An expense or a journey with an own vehicle that the association reimburses, linked to a project or a campaign and
 * posted to the general ledger when paid. The plate is personal data and is stored encrypted.
 *
 * @property string $id
 * @property string $kind
 * @property string $status
 * @property string $amount
 * @property ?string $journal_entry_id
 * @property-read Member $member
 */
final class Reimbursement extends AssociationRecord
{
    public const string Mileage = 'mileage';

    public const string Expense = 'expense';

    public const string Submitted = 'submitted';

    public const string Approved = 'approved';

    public const string Rejected = 'rejected';

    public const string Paid = 'paid';

    protected $table = 'assoc_reimbursements';

    protected function casts(): array
    {
        return [
            'incurred_on' => 'date',
            'paid_on' => 'date',
            'decided_at' => 'datetime',
            'plate' => 'encrypted',
            'distance_km' => 'decimal:2',
            'unit_price' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            'member_name' => Member::nameSql('assoc_reimbursements.member_id'),
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ReimbursementAttachment::class, 'reimbursement_id');
    }
}
