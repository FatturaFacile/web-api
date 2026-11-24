<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use LogicException;

/**
 * Append-only audit entry of a signature request: it can be created, never changed or deleted.
 *
 * @property string $request_id
 * @property string|null $signer_id
 * @property string $event
 * @property string|null $actor_id
 * @property array<string, mixed>|null $metadata
 */
final class ContractSignatureEvent extends BaseEnterpriseModel
{
    protected $table = 'contract_signature_events';

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    protected static function booted(): void
    {
        self::updating(static fn () => throw new LogicException('Signature audit events are append-only.'));
        self::deleting(static fn () => throw new LogicException('Signature audit events are append-only.'));
    }
}
