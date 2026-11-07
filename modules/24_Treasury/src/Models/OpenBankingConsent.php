<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class OpenBankingConsent extends BaseEnterpriseModel
{
    protected $table = 'open_banking_consents';

    /**
     * @var list<string>
     */
    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
