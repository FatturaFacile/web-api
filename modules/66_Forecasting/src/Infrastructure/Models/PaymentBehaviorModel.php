<?php

declare(strict_types=1);

namespace Modules\Forecasting\Infrastructure\Models;

use DateTimeImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\Forecasting\Domain\Entities\CustomerPaymentBehaviorModel;
use Modules\Forecasting\Domain\Enums\PaymentRiskTier;

/**
 * Persisted payment behaviour of a customer (one row per tenant and customer).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $customer_id
 * @property int $sample_size
 * @property string $average_days_late
 * @property string $std_dev_days_late
 * @property string $on_time_payment_probability
 * @property int $predicted_collection_date_offset_days
 * @property PaymentRiskTier $payment_risk_tier
 * @property Carbon|null $last_trained_at
 */
final class PaymentBehaviorModel extends BaseEnterpriseModel
{
    protected $table = 'customer_payment_behavior_models';

    protected function casts(): array
    {
        return [
            'sample_size' => 'integer',
            'average_days_late' => 'decimal:2',
            'std_dev_days_late' => 'decimal:2',
            'on_time_payment_probability' => 'decimal:4',
            'predicted_collection_date_offset_days' => 'integer',
            'payment_risk_tier' => PaymentRiskTier::class,
            'last_trained_at' => 'datetime',
        ];
    }

    public function toDomain(): CustomerPaymentBehaviorModel
    {
        return new CustomerPaymentBehaviorModel(
            $this->id,
            $this->tenant_id,
            $this->customer_id,
            $this->sample_size,
            (float) $this->average_days_late,
            (float) $this->std_dev_days_late,
            (float) $this->on_time_payment_probability,
            $this->predicted_collection_date_offset_days,
            $this->payment_risk_tier,
            $this->last_trained_at !== null ? DateTimeImmutable::createFromInterface($this->last_trained_at) : new DateTimeImmutable,
        );
    }
}
