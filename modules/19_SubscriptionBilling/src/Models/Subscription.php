<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use App\Models\Contact;
use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A subscription contract of a customer (sub_subscriptions): the single subscription engine of the application.
 *
 * Statuses: trialing, active, past_due (an invoice is in dunning), paused, suspended (dunning), pending (waiting for
 * approval), cancelled, expired. `current_period_start` is the first day of the next period to bill.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $subscription_number
 * @property string|null $name
 * @property string $customer_id
 * @property string $plan_id
 * @property string $status
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $current_period_start
 * @property CarbonImmutable|null $current_period_end
 * @property CarbonImmutable|null $billing_anchor_date
 * @property CarbonImmutable|null $term_end_date
 * @property CarbonImmutable|null $trial_end_date
 * @property CarbonImmutable|null $cancellation_effective_date
 * @property CarbonImmutable|null $resume_on
 * @property string $billing_alignment
 * @property string $renewal_type
 * @property int $contract_term_months
 * @property bool $auto_renew
 * @property int $cancellation_notice_days
 * @property int $renewal_notice_days
 * @property int $payment_terms_days
 * @property string $payment_method
 * @property string $currency
 * @property string $mrr
 * @property string $arr
 * @property bool $is_locked
 * @property bool $cancel_at_period_end
 * @property string|null $legacy_subscription_id
 * @property array<string, mixed>|null $metadata
 * @property string|null $cancellation_type
 * @property string|null $cancellation_reason_code
 * @property string|null $cancellation_reason
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $paused_at
 * @property Carbon|null $renewal_notified_at
 * @property Carbon|null $last_billed_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Subscription extends BaseEnterpriseModel
{
    /** Statuses in which the subscription is billed. */
    public const array BILLABLE_STATUSES = ['trialing', 'active', 'past_due'];

    /** Statuses that end the subscription. */
    public const array CLOSED_STATUSES = ['cancelled', 'expired'];

    protected $table = 'sub_subscriptions';

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'current_period_start' => 'immutable_date',
            'current_period_end' => 'immutable_date',
            'billing_anchor_date' => 'immutable_date',
            'term_end_date' => 'immutable_date',
            'trial_end_date' => 'immutable_date',
            'cancellation_effective_date' => 'immutable_date',
            'resume_on' => 'immutable_date',
            'contract_term_months' => 'integer',
            'auto_renew' => 'boolean',
            'cancel_at_period_end' => 'boolean',
            'cancellation_notice_days' => 'integer',
            'renewal_notice_days' => 'integer',
            'cancelled_at' => 'datetime',
            'paused_at' => 'datetime',
            'renewal_notified_at' => 'datetime',
            'last_billed_at' => 'datetime',
            'billing_day_of_month' => 'integer',
            'payment_terms_days' => 'integer',
            'mrr' => 'decimal:2',
            'arr' => 'decimal:2',
            'renewal_price_increase_percent' => 'decimal:4',
            'is_locked' => 'boolean',
            'terms' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    /**
     * The customer (a contact of the company).
     *
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    /**
     * @return HasMany<SubscriptionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class, 'subscription_id');
    }

    /**
     * @return HasMany<SubscriptionVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(SubscriptionVersion::class, 'subscription_id')->orderBy('version_number', 'desc');
    }

    /**
     * Billing periods and their invoices, oldest first.
     *
     * @return HasMany<InvoiceSchedule, $this>
     */
    public function invoiceSchedules(): HasMany
    {
        return $this->hasMany(InvoiceSchedule::class, 'subscription_id')->orderBy('service_period_start')->orderBy('created_at');
    }

    /**
     * @return HasOne<RevenueContract, $this>
     */
    public function revenueContract(): HasOne
    {
        return $this->hasOne(RevenueContract::class, 'subscription_id');
    }

    /**
     * @return HasMany<ChurnPrediction, $this>
     */
    public function churnPredictions(): HasMany
    {
        return $this->hasMany(ChurnPrediction::class, 'subscription_id')->orderBy('analyzed_at', 'desc');
    }

    /**
     * @return HasMany<DunningRecord, $this>
     */
    public function dunningRecords(): HasMany
    {
        return $this->hasMany(DunningRecord::class, 'subscription_id')->orderBy('created_at', 'desc');
    }

    /**
     * @return HasMany<UsageEvent, $this>
     */
    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class, 'subscription_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isCancelled(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    /**
     * Billing anchor of the anniversary periods: the first billing date.
     */
    public function anchor(): CarbonImmutable
    {
        return $this->billing_anchor_date ?? $this->start_date ?? CarbonImmutable::today();
    }
}
