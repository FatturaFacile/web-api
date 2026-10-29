<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InvoiceSchedule extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $table = 'sub_invoice_schedules';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'scheduled_billing_date' => 'date',
            'service_period_start' => 'date',
            'service_period_end' => 'date',
            'estimated_amount' => 'decimal:2',
        ];
    }

    protected static function newFactory(): ?Factory
    {
        $factoryName = 'Database\\Factories\\InvoiceScheduleFactory';
        if (class_exists($factoryName)) {
            return $factoryName::new();
        }

        return null;
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }
}
