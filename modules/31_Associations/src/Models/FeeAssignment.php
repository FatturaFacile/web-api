<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What one person owes under a plan, with the discount applied and, for packages, the lessons left.
 */
final class FeeAssignment extends AssociationRecord
{
    public const string Active = 'active';

    public const string Cancelled = 'cancelled';

    protected $table = 'assoc_fee_assignments';

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'gross_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'per_attendance_amount' => 'decimal:2',
            'lessons_total' => 'integer',
            'lessons_remaining' => 'integer',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(FeePlan::class, 'fee_plan_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'payer_contact_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function instalments(): HasMany
    {
        return $this->hasMany(FeeInstalment::class, 'fee_assignment_id')->orderBy('sequence');
    }
}
