<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Associations\Enums\ApprovalMode;

/**
 * A public enrolment form. Its random token is the link: the tenant of a public request comes from here and only here.
 */
final class EnrolmentForm extends AssociationRecord
{
    protected $table = 'assoc_enrolment_forms';

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'approval_mode' => ApprovalMode::class,
            'is_active' => 'boolean',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
        ];
    }

    public function year(): BelongsTo
    {
        return $this->belongsTo(AssociativeYear::class, 'year_id');
    }

    public function membershipType(): BelongsTo
    {
        return $this->belongsTo(MembershipType::class, 'membership_type_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EnrolmentFormDocument::class, 'form_id')->orderBy('sort_order');
    }

    public function isOpen(): bool
    {
        return $this->is_active
            && ($this->opens_at === null || $this->opens_at->isPast())
            && ($this->closes_at === null || $this->closes_at->isFuture());
    }
}
