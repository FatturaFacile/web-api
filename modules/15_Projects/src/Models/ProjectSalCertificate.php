<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * A SAL progress certificate (Stato Avanzamento Lavori, Akontorechnung / Teilrechnung): the incremental earned value of
 * a period, the advance recouped, the guarantee retention withheld and the amounts to invoice and to collect.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $project_id
 * @property string $billing_contract_id
 * @property int $sal_number
 * @property CarbonInterface $period_start_date
 * @property CarbonInterface $period_end_date
 * @property string $previous_completion_percent
 * @property string $cumulative_completion_percent
 * @property string $cumulative_gross_earned_value
 * @property string $previous_sal_cumulative_billed
 * @property string $current_period_gross_sal
 * @property string $advance_deduction_amount
 * @property string $guarantee_retention_withheld
 * @property string $invoice_amount
 * @property string $net_billable_amount
 * @property bool $is_final
 * @property string $status
 * @property CarbonInterface|null $customer_approved_at
 * @property string|null $customer_approved_by
 * @property string|null $sales_invoice_id
 * @property string|null $notes
 */
final class ProjectSalCertificate extends BaseEnterpriseModel
{
    protected $table = 'project_sal_certificates';

    protected function casts(): array
    {
        return [
            'sal_number' => 'integer',
            'period_start_date' => 'date',
            'period_end_date' => 'date',
            'previous_completion_percent' => 'decimal:4',
            'cumulative_completion_percent' => 'decimal:4',
            'cumulative_gross_earned_value' => 'decimal:4',
            'previous_sal_cumulative_billed' => 'decimal:4',
            'current_period_gross_sal' => 'decimal:4',
            'advance_deduction_amount' => 'decimal:4',
            'guarantee_retention_withheld' => 'decimal:4',
            'invoice_amount' => 'decimal:4',
            'net_billable_amount' => 'decimal:4',
            'is_final' => 'boolean',
            'customer_approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProjectBillingContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(ProjectBillingContract::class, 'billing_contract_id');
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }
}
