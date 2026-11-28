<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One compensation paid to a recipient, with every tax figure frozen at the payment date (cash principle).
 *
 * @property string $recipient_profile_id
 * @property string|null $inl_notification_id
 * @property string $regime
 * @property string $jurisdiction
 * @property string $currency
 * @property string|null $document_number
 * @property Carbon $service_date
 * @property Carbon $payment_date
 * @property int $tax_year
 * @property string $accrual_month
 * @property string $status
 * @property string $gross_amount
 * @property string $flat_rate_deduction
 * @property string $taxable_base
 * @property string $withholding_rate
 * @property string $irpef_withholding
 * @property string $inps_base
 * @property string $inps_total
 * @property string $inps_worker
 * @property string $inps_company
 * @property string $stamp_duty
 * @property string $enasarco_base
 * @property string $enasarco_agent
 * @property string $enasarco_company
 * @property string $firr_accrual
 * @property string $avs_base
 * @property string $avs_employee
 * @property string $avs_employer
 * @property string $acquisition_expenses
 * @property string $source_tax
 * @property string $non_taxable_amount
 * @property string|null $non_taxable_code
 * @property string|null $cu_causal
 * @property string $net_payable
 * @property string $company_cost
 * @property Carbon|null $f24_due_date
 * @property list<array<string, mixed>>|null $f24_lines
 * @property bool $uniemens_required
 * @property string|null $payment_reference
 * @property array<string, mixed>|null $breakdown
 * @property list<string>|null $warnings
 * @property string|null $notes
 */
final class RecipientSettlement extends BaseEnterpriseModel
{
    protected $table = 'fiscal_recipient_settlements';

    protected function casts(): array
    {
        $decimals = ['gross_amount', 'flat_rate_deduction', 'taxable_base', 'irpef_withholding', 'inps_base', 'inps_total', 'inps_worker', 'inps_company', 'stamp_duty', 'enasarco_base', 'enasarco_agent', 'enasarco_company', 'firr_accrual', 'avs_base', 'avs_employee', 'avs_employer', 'acquisition_expenses', 'source_tax', 'non_taxable_amount', 'net_payable', 'company_cost'];

        return array_fill_keys($decimals, 'decimal:2') + [
            'withholding_rate' => 'decimal:4',
            'service_date' => 'date',
            'payment_date' => 'date',
            'f24_due_date' => 'date',
            'tax_year' => 'integer',
            'uniemens_required' => 'boolean',
            'f24_lines' => 'array',
            'breakdown' => 'array',
            'warnings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<RecipientProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(RecipientProfile::class, 'recipient_profile_id');
    }

    /**
     * @return BelongsTo<OccasionalInlNotification, $this>
     */
    public function inlNotification(): BelongsTo
    {
        return $this->belongsTo(OccasionalInlNotification::class, 'inl_notification_id');
    }
}
