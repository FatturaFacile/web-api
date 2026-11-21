<?php

declare(strict_types=1);

namespace Modules\Quoting\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only, hash-chained event of a quote (each row carries the hash of the previous one): changing or removing a
 * row breaks the chain and shows in the audit certificate verification.
 *
 * @property string $id
 * @property string $quote_id
 * @property int $sequence
 * @property string $event
 * @property string|null $actor
 * @property string|null $ip_address
 * @property array<string, mixed>|null $payload
 * @property string|null $previous_hash
 * @property string $event_hash
 * @property Carbon $occurred_at
 */
final class CpqAuditEvent extends BaseEnterpriseModel
{
    protected $table = 'cpq_audit_events';

    protected static function booted(): void
    {
        self::updating(static fn () => throw new LogicException('Audit events are append-only.'));
        self::deleting(static fn () => throw new LogicException('Audit events are append-only.'));
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime', 'sequence' => 'integer'];
    }
}
