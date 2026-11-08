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
use Modules\Invoicing\Models\SalesInvoice;

/**
 * A billing period of a subscription and the invoice issued for it (recurring, proration or setup), or the attempt that
 * failed. One recurring row per period in status processing/invoiced: the idempotency key of the billing.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $subscription_id
 * @property string $billing_kind
 * @property string $status
 * @property Carbon|null $scheduled_billing_date
 * @property Carbon|null $service_period_start
 * @property Carbon|null $service_period_end
 * @property string $estimated_amount
 * @property string $net_amount
 * @property string|null $invoice_id
 * @property string|null $credit_note_id
 * @property string|null $billing_run_id
 * @property string|null $error
 * @property Carbon|null $created_at
 */
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
            'net_amount' => 'decimal:2',
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

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    /**
     * The invoice issued for the period (a document of the invoicing module).
     *
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }
}
