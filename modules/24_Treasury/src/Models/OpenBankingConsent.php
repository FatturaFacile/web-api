<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * @property string $driver
 * @property string $iban
 * @property string $external_consent_id
 * @property string|null $access_token
 * @property string $status
 * @property CarbonInterface|null $expires_at
 */
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
