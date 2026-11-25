<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\IndustrialLaundry\Domain\Enums\BillingModel;
use Modules\IndustrialLaundry\Domain\Enums\BillingRunStatus;
use Modules\IndustrialLaundry\Domain\Enums\Jurisdiction;

/**
 * The monthly wash and rental billing of a contract for one period, with the draft invoice it produced.
 *
 * @property string $contract_id
 * @property string $customer_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property BillingRunStatus $status
 * @property BillingModel $billing_model
 * @property Jurisdiction $jurisdiction
 * @property string $currency
 * @property int $washed_pieces
 * @property string $net_weight_kg
 * @property string $wash_amount
 * @property string $minimum_top_up_amount
 * @property string $rental_amount
 * @property string $unreturned_amount
 * @property string $subtotal
 * @property string $tax_rate
 * @property string $tax_amount
 * @property string $total
 * @property string|null $invoice_id
 * @property string|null $invoice_number
 * @property array<string, mixed>|null $compliance_report
 */
final class BillingRun extends BaseEnterpriseModel
{
    protected $table = 'ilr_billing_runs';

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => BillingRunStatus::class,
            'billing_model' => BillingModel::class,
            'jurisdiction' => Jurisdiction::class,
            'washed_pieces' => 'integer',
            'net_weight_kg' => 'decimal:3',
            'wash_amount' => 'decimal:4',
            'minimum_top_up_amount' => 'decimal:4',
            'rental_amount' => 'decimal:4',
            'unreturned_amount' => 'decimal:4',
            'subtotal' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'compliance_report' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ParContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(ParContract::class, 'contract_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    /**
     * @return HasMany<BillingRunLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BillingRunLine::class, 'billing_run_id')->orderBy('position');
    }
}
