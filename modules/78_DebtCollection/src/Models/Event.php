<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * One entry of the chronological dunning history of a customer.
 *
 * @property string $customer_id
 * @property string $event
 * @property int|null $level
 * @property string|null $amount
 * @property string|null $currency
 * @property string $subject_type
 * @property string $subject_id
 * @property array<string, mixed>|null $payload
 * @property string|null $actor_id
 * @property Carbon $occurred_at
 */
final class Event extends BaseEnterpriseModel
{
    protected $table = 'dc_events';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'payload' => 'array', 'occurred_at' => 'datetime', 'level' => 'integer'];
    }
}
