<?php

declare(strict_types=1);

namespace Modules\WorkspaceSync\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * One user's consent to one provider. Tokens are encrypted and never leave the API.
 */
final class WorkspaceConnection extends BaseEnterpriseModel
{
    protected $table = 'workspace_connections';

    /**
     * @var list<string>
     */
    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'consent_given_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Is active.
     */
    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->access_token !== null;
    }
}
