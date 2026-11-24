<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry of the history of an open item: who changed its status, when and why.
 *
 * @property string $id
 * @property string $open_item_id
 * @property string $event
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $reason
 * @property string|null $actor_id
 * @property array<string, mixed>|null $context
 * @property Carbon $occurred_at
 */
final class ApOpenItemEvent extends BaseEnterpriseModel
{
    protected $table = 'ap_open_item_events';

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ApOpenItem, $this>
     */
    public function openItem(): BelongsTo
    {
        return $this->belongsTo(ApOpenItem::class, 'open_item_id');
    }
}
