<?php

declare(strict_types=1);

namespace Modules\Intercompany\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Append-only log of what the mirror engine did: mirror created, confirmed, reversed, payments and netting synchronised.
 *
 * @property array<string, mixed>|null $context
 * @property string|null $amount
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 */
final class SyncEvent extends BaseEnterpriseModel
{
    protected $table = 'ic_sync_events';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'amount' => 'decimal:4',
            'occurred_at' => 'datetime',
        ];
    }
}
