<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * A service contract of a customer. The annual value is a decimal string, never a float.
 *
 * @property string $annual_value
 * @property string $included_hours
 * @property string $consumed_hours
 * @property string|null $sla_response_hours
 * @property int $low_balance_threshold_percent
 * @property Carbon $start_date
 * @property array<string, mixed>|null $preventive_frequency_rule
 * @property Carbon|null $end_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $last_residual_report_at
 * @property Carbon|null $low_balance_notified_at
 */
final class ServiceContract extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'esm_service_contracts';

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function booted(): void
    {
        parent::booted();

        self::creating(function (self $model): void {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::orderedUuid();
            }
        });
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'renewal_date' => 'date',
            'annual_value' => 'decimal:2',
            'auto_renew' => 'boolean',
            'is_locked' => 'boolean',
            'terms' => 'array',
            'entitlements' => 'array',
            'metadata' => 'array',
            'included_hours' => 'decimal:2',
            'consumed_hours' => 'decimal:2',
            'sla_response_hours' => 'decimal:2',
            'low_balance_threshold_percent' => 'integer',
            'last_residual_report_at' => 'datetime',
            'low_balance_notified_at' => 'datetime',
            'preventive_frequency_rule' => 'array',
            'callout_fee_amount' => 'decimal:2',
            'hourly_labor_rate' => 'decimal:2',
        ];
    }

    public function lockByWorkflow(WorkflowInstance $instance): void
    {
        $this->update([
            'workflow_instance_id' => $instance->id,
            'approval_status' => 'pending_approval',
            'is_locked' => true,
        ]);
    }

    public function isLockedByWorkflow(): bool
    {
        return (bool) $this->is_locked;
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['title'] ?? $value ?? '',
            set: fn ($value) => ['title' => $value]
        );
    }

    protected function contractValue(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => (float) ($attributes['annual_value'] ?? $value ?? 0.0),
            set: fn ($value) => ['annual_value' => $value]
        );
    }

    protected function contractType(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes): string {
                /** @var array<string, mixed> $terms */
                $terms = json_decode((string) ($attributes['terms'] ?? '{}'), true) ?: [];

                return (string) ($terms['contract_type'] ?? 'sla');
            },
            set: function ($value, $attributes): array {
                /** @var array<string, mixed> $terms */
                $terms = json_decode((string) ($attributes['terms'] ?? '{}'), true) ?: [];
                $terms['contract_type'] = (string) $value;

                return ['terms' => json_encode($terms)];
            }
        );
    }

    protected function billingCycle(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes): string {
                /** @var array<string, mixed> $terms */
                $terms = json_decode((string) ($attributes['terms'] ?? '{}'), true) ?: [];

                return (string) ($terms['billing_cycle'] ?? 'monthly');
            },
            set: function ($value, $attributes): array {
                /** @var array<string, mixed> $terms */
                $terms = json_decode((string) ($attributes['terms'] ?? '{}'), true) ?: [];
                $terms['billing_cycle'] = (string) $value;

                return ['terms' => json_encode($terms)];
            }
        );
    }

    public function remainingHours(): string
    {
        return bcsub((string) $this->getAttribute('included_hours'), (string) $this->getAttribute('consumed_hours'), 2);
    }

    public function isLowBalance(): bool
    {
        if (bccomp((string) $this->getAttribute('included_hours'), '0', 2) <= 0) {
            return false;
        }

        $threshold = bcdiv(bcmul((string) $this->getAttribute('included_hours'), (string) $this->low_balance_threshold_percent, 4), '100', 2);

        return bccomp($this->remainingHours(), $threshold, 2) <= 0;
    }

    /**
     * @return BelongsToMany<SerializedEquipmentPassport, $this>
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(SerializedEquipmentPassport::class, 'esm_service_contract_assets', 'service_contract_id', 'equipment_passport_id')
            ->withPivot(['id', 'tenant_id'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<ContractHourTransaction, $this>
     */
    public function hourTransactions(): HasMany
    {
        return $this->hasMany(ContractHourTransaction::class, 'service_contract_id')->orderBy('occurred_at')->orderBy('created_at');
    }

    public function supportPlan(): BelongsTo
    {
        return $this->belongsTo(SupportPlan::class, 'support_plan_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'service_contract_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'service_contract_id');
    }
}
