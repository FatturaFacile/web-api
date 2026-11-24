<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The affiliation of this company to a parent body. The row belongs to the affiliate: the tenant scope hides it from
 * the parent, which reaches it only through the network service.
 */
final class NetworkLink extends AssociationRecord
{
    public const string Pending = 'pending';

    public const string Active = 'active';

    public const string Withdrawn = 'withdrawn';

    protected $table = 'assoc_network_links';

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'consent_granted_at' => 'datetime',
            'consent_withdrawn_at' => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'parent_tenant_id');
    }
}
