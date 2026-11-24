<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person holds an office for a period.
 */
final class MemberSocialRole extends AssociationRecord
{
    protected $table = 'assoc_member_social_roles';

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function socialRole(): BelongsTo
    {
        return $this->belongsTo(SocialRole::class, 'social_role_id');
    }
}
