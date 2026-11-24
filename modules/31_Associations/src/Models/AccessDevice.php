<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reader. It authenticates with its public key and a secret of which only the hash is stored.
 */
final class AccessDevice extends AssociationRecord
{
    protected $table = 'assoc_access_devices';

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(AccessArea::class, 'area_id');
    }
}
