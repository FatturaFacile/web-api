<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Associations\Enums\AuthorizationStatus;

/**
 * A text a guardian accepts for a minor. The hash of the text and the evidence of the acceptance stay with the record.
 */
final class Authorization extends AssociationRecord
{
    protected $table = 'assoc_authorizations';

    protected $hidden = ['signed_ip', 'signed_user_agent', 'signed_with_token_id'];

    protected function casts(): array
    {
        return [
            'status' => AuthorizationStatus::class,
            'valid_until' => 'date',
            'signed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'signed_name' => 'encrypted',
        ];
    }

    public function minor(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'minor_member_id');
    }

    public function guardianship(): BelongsTo
    {
        return $this->belongsTo(Guardianship::class, 'guardianship_id');
    }
}
