<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A support mailbox of a tenant. The IMAP credentials are encrypted at rest and never serialized.
 */
final class SupportMailbox extends BaseEnterpriseModel
{
    protected $table = 'esm_support_mailboxes';

    protected $hidden = ['imap_username', 'imap_password'];

    protected function casts(): array
    {
        return [
            'imap_port' => 'integer',
            'imap_username' => 'encrypted',
            'imap_password' => 'encrypted',
            'is_active' => 'boolean',
            'last_uid' => 'integer',
            'last_polled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ServiceTeam, $this>
     */
    public function defaultTeam(): BelongsTo
    {
        return $this->belongsTo(ServiceTeam::class, 'default_team_id');
    }
}
