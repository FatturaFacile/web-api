<?php

declare(strict_types=1);

namespace Modules\Visitors\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A reception tablet. It authenticates with its public key and a secret of which only the hash is stored; its row tells
 * the tenant, never the client.
 */
final class VisitorKiosk extends VisitorsRecord
{
    use SoftDeletes;

    protected $table = 'visitor_kiosks';

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_seen_at' => 'datetime'];
    }
}
