<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Database\Factories\TimePeriodClosingFactory;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The monthly closing of the working time records of a company: while a month is closed no clock event, daily record
 * or legacy time entry of that month can be created, corrected or deleted. Reopening needs a reason and is counted.
 */
#[UseFactory(TimePeriodClosingFactory::class)]
final class TimePeriodClosing extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const string StatusClosed = 'closed';

    public const string StatusReopened = 'reopened';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'hr_time_period_closings';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Whether the month is currently closed.
     */
    public function isClosed(): bool
    {
        return $this->status === self::StatusClosed;
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'reopen_count' => 'integer',
            'summary' => 'array',
        ];
    }
}
