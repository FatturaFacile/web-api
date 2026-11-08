<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A billing run of a tenant and its outcome.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $run_number
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon|null $execution_date
 * @property int $total_subscriptions_evaluated
 * @property int $successful_invoices_count
 * @property int $failed_invoices_count
 * @property string $total_invoiced_amount
 * @property string $status
 * @property array<int, array<string, string>>|null $errors_log
 * @property Carbon|null $created_at
 */
final class BillingRun extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $table = 'sub_billing_runs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'execution_date' => 'date',
            'total_subscriptions_evaluated' => 'integer',
            'successful_invoices_count' => 'integer',
            'failed_invoices_count' => 'integer',
            'total_invoiced_amount' => 'decimal:2',
            'errors_log' => 'array',
        ];
    }

    protected static function newFactory(): ?Factory
    {
        $factoryName = 'Database\\Factories\\BillingRunFactory';
        if (class_exists($factoryName)) {
            return $factoryName::new();
        }

        return null;
    }
}
