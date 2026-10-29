<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PlanFeature extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $table = 'sub_plan_features';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'included_quantity' => 'decimal:4',
            'overage_unit_price' => 'decimal:4',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    protected static function newFactory(): ?Factory
    {
        $factoryName = 'Database\\Factories\\PlanFeatureFactory';
        if (class_exists($factoryName)) {
            return $factoryName::new();
        }

        return null;
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }
}
