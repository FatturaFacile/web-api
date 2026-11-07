<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Associations\Enums\CredentialKind;

/**
 * What a person shows to a reader: a QR with a signed, revocable code or a badge. No personal data is stored or printed
 * in it.
 */
final class Credential extends AssociationRecord
{
    protected $table = 'assoc_credentials';

    protected $hidden = ['lookup_hash', 'public_id'];

    protected function casts(): array
    {
        return ['kind' => CredentialKind::class, 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
