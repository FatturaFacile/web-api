<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Mod 155 Marketing Automation — ConsentEvent.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $contact_id
 * @property string $event_type
 * @property string|null $lawful_basis
 * @property string|null $strategy_code
 * @property string|null $source
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $details
 * @property \Illuminate\Support\Carbon|null $occurred_at
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class ConsentEvent extends BaseEnterpriseModel
{
    protected $table = 'ma_consent_events';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The consent ledger is append-only: it is the evidence in front of a regulator.
        self::updating(static function (): never {
            throw new \LogicException('Consent events are immutable.');
        });
        self::deleting(static function (): never {
            throw new \LogicException('Consent events are immutable.');
        });
    }
}
