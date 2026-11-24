<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A monthly revenue slice of a performance obligation.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $performance_obligation_id
 * @property Carbon|null $period_date
 * @property bool $is_recognized
 * @property Carbon|null $recognized_at
 * @property string|null $journal_entry_id
 */
final class RevenueSchedule extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $table = 'sub_revenue_schedules';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'period_date' => 'date',
            'scheduled_amount' => 'decimal:2',
            'recognized_amount' => 'decimal:2',
            'is_recognized' => 'boolean',
            'recognized_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ?Factory
    {
        $factoryName = 'Database\\Factories\\RevenueScheduleFactory';
        if (class_exists($factoryName)) {
            return $factoryName::new();
        }

        return null;
    }

    /**
     * @return BelongsTo<PerformanceObligation, $this>
     */
    public function performanceObligation(): BelongsTo
    {
        return $this->belongsTo(PerformanceObligation::class, 'performance_obligation_id');
    }
}
