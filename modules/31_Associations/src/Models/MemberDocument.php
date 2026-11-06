<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file of a person, on the private disk, with an optional expiry.
 */
final class MemberDocument extends AssociationRecord
{
    protected $table = 'assoc_member_documents';

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return ['expires_on' => 'date', 'size' => 'integer', 'visible_to_member' => 'boolean'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
