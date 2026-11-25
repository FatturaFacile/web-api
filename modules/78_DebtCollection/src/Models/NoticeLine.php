<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The share of a notice that concerns one open item.
 *
 * @property string $notice_id
 * @property string $receivable_item_id
 * @property string|null $document_number
 * @property Carbon $due_date
 * @property string $principal
 * @property string $interest
 * @property int $interest_days
 * @property string $rate_percent
 */
final class NoticeLine extends BaseEnterpriseModel
{
    protected $table = 'dc_notice_lines';

    protected function casts(): array
    {
        return ['principal' => 'decimal:2', 'interest' => 'decimal:2', 'interest_days' => 'integer', 'rate_percent' => 'decimal:4', 'due_date' => 'date'];
    }

    /**
     * @return BelongsTo<Notice, $this>
     */
    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class, 'notice_id');
    }
}
