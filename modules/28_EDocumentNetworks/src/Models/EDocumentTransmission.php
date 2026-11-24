<?php

declare(strict_types=1);

namespace Modules\EDocumentNetworks\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Eloquent model of the edocument transmission (tenant scoped where it has a tenant_id).
 *
 * A refused hand-over is a `failed` row that keeps the encrypted payload for a retry; an accepted one never keeps it.
 *
 * @property Carbon|null $last_attempt_at
 */
final class EDocumentTransmission extends BaseEnterpriseModel
{
    public const string Failed = 'failed';

    protected $table = 'edocument_transmissions';

    /**
     * @var list<string>
     */
    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted',
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
        ];
    }

    /**
     * Whether the transmission can be handed to the driver again.
     */
    public function canRetry(): bool
    {
        return $this->status === self::Failed && $this->getRawOriginal('payload') !== null;
    }
}
