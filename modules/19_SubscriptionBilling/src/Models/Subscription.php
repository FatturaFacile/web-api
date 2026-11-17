<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Subscription extends BaseEnterpriseModel
{
    protected $table = 'sub_subscriptions';

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'current_period_start' => 'date',
            'current_period_end' => 'date',
            'contract_term_months' => 'integer',
            'auto_renew' => 'boolean',
            'cancellation_notice_days' => 'integer',
            'cancelled_at' => 'datetime',
            'billing_day_of_month' => 'integer',
            'payment_terms_days' => 'integer',
            'mrr' => 'decimal:2',
            'arr' => 'decimal:2',
            'is_locked' => 'boolean',
            'last_billed_period_end' => 'date',
            'indexation_ratio_pct' => 'decimal:2',
            'indexation_cap_pct' => 'decimal:3',
            'indexation_base_month' => 'date',
            'next_indexation_date' => 'date',
            'last_indexed_at' => 'datetime',
            'terms' => 'array',
            'metadata' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class, 'subscription_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SubscriptionVersion::class, 'subscription_id')->orderBy('version_number', 'desc');
    }

    public function invoiceSchedules(): HasMany
    {
        return $this->hasMany(InvoiceSchedule::class, 'subscription_id')->orderBy('scheduled_billing_date');
    }

    public function revenueContract(): HasOne
    {
        return $this->hasOne(RevenueContract::class, 'subscription_id');
    }

    public function indexationAdjustments(): HasMany
    {
        return $this->hasMany(IndexationAdjustment::class, 'subscription_id')->orderBy('effective_date', 'desc');
    }

    public function churnPredictions(): HasMany
    {
        return $this->hasMany(ChurnPrediction::class, 'subscription_id')->orderBy('analyzed_at', 'desc');
    }

    public function dunningRecords(): HasMany
    {
        return $this->hasMany(DunningRecord::class, 'subscription_id')->orderBy('created_at', 'desc');
    }

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
        return in_array($this->status, ['cancelled', 'expired'], true);
    }
}
