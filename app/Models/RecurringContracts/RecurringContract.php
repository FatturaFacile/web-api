<?php

declare(strict_types=1);

namespace App\Models\RecurringContracts;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Contact;
use App\Models\PaymentTerm;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $customer_id
 * @property string $contract_number
 * @property string $title
 * @property string $contract_type
 * @property string $status
 * @property CarbonInterface|null $start_date
 * @property CarbonInterface|null $end_date
 * @property string $billing_frequency
 * @property int|null $custom_interval_days
 * @property string $billing_timing
 * @property CarbonInterface|null $next_billing_date
 * @property CarbonInterface|null $last_billed_date
 * @property bool $auto_renew
 * @property int|null $renewal_period_months
 * @property int $notice_period_days
 * @property string $currency
 * @property string|null $payment_term_id
 * @property string|null $payment_method
 * @property bool $invoice_auto_issue
 * @property string|null $notes
 * @property string|null $cancellation_reason
 * @property CarbonInterface|null $cancelled_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class RecurringContract extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'recurring_contracts';

    protected $fillable = [
        'customer_id',
        'contract_number',
        'title',
        'contract_type',
        'status',
        'start_date',
        'end_date',
        'billing_frequency',
        'custom_interval_days',
        'billing_timing',
        'next_billing_date',
        'last_billed_date',
        'auto_renew',
        'renewal_period_months',
        'notice_period_days',
        'currency',
        'payment_term_id',
        'payment_method',
        'invoice_auto_issue',
        'notes',
        'cancellation_reason',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'next_billing_date' => 'date',
            'last_billed_date' => 'date',
            'auto_renew' => 'boolean',
            'invoice_auto_issue' => 'boolean',
            'custom_interval_days' => 'integer',
            'renewal_period_months' => 'integer',
            'notice_period_days' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    /**
     * @return BelongsTo<PaymentTerm, $this>
     */
    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_term_id');
    }

    /**
     * @return HasMany<RecurringContractLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RecurringContractLine::class, 'recurring_contract_id')->orderBy('sort_order');
    }

    /**
     * @return HasOne<ContractIndexationClause, $this>
     */
    public function indexationClause(): HasOne
    {
        return $this->hasOne(ContractIndexationClause::class, 'recurring_contract_id')->where('is_active', true);
    }

    /**
     * @return HasMany<ContractIndexationClause, $this>
     */
    public function indexationClauses(): HasMany
    {
        return $this->hasMany(ContractIndexationClause::class, 'recurring_contract_id');
    }

    /**
     * @return HasMany<ContractIndexationLog, $this>
     */
    public function indexationLogs(): HasMany
    {
        return $this->hasMany(ContractIndexationLog::class, 'recurring_contract_id')->orderByDesc('applied_at');
    }

    /**
     * @return HasMany<RecurringBillingRunItem, $this>
     */
    public function billingRunItems(): HasMany
    {
        return $this->hasMany(RecurringBillingRunItem::class, 'recurring_contract_id')->orderByDesc('created_at');
    }

    /**
     * Calculate recurring subtotal using bcmath.
     */
    public function calculateSubtotal(): string
    {
        $total = '0.0000';
        foreach ($this->lines as $line) {
            $total = bcadd($total, $line->calculateLineSubtotal(), 4);
        }

        return $total;
    }

    /**
     * Calculate recurring total (subtotal + tax) using bcmath.
     */
    public function calculateTotal(): string
    {
        $total = '0.0000';
        foreach ($this->lines as $line) {
            $total = bcadd($total, $line->calculateLineTotal(), 4);
        }

        return $total;
    }
}
