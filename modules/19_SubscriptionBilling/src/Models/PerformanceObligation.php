<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PerformanceObligation extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $table = 'sub_performance_obligations';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'standalone_selling_price' => 'decimal:2',
            'allocated_transaction_price' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'recognized_revenue_to_date' => 'decimal:2',
            'deferred_revenue_balance' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    protected static function newFactory(): ?Factory
    {
        $factoryName = 'Database\\Factories\\PerformanceObligationFactory';
        if (class_exists($factoryName)) {
            return $factoryName::new();
        }

        return null;
    }

    public function revenueContract(): BelongsTo
    {
        return $this->belongsTo(RevenueContract::class, 'revenue_contract_id');
    }

    public function revenueSchedules(): HasMany
    {
        return $this->hasMany(RevenueSchedule::class, 'performance_obligation_id')->orderBy('period_date');
    }

    public function recognitionEntries(): HasMany
    {
        return $this->hasMany(RevenueRecognitionEntry::class, 'performance_obligation_id')->orderBy('recognition_date');
    }
}
