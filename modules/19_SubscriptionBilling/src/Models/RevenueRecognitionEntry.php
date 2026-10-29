<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RevenueRecognitionEntry extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $table = 'sub_revenue_recognition_entries';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'recognition_date' => 'date',
            'amount' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    protected static function newFactory(): ?Factory
    {
        $factoryName = 'Database\\Factories\\RevenueRecognitionEntryFactory';
        if (class_exists($factoryName)) {
            return $factoryName::new();
        }

        return null;
    }

    public function performanceObligation(): BelongsTo
    {
        return $this->belongsTo(PerformanceObligation::class, 'performance_obligation_id');
    }
}
