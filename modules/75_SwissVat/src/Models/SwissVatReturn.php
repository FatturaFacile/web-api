<?php

declare(strict_types=1);

namespace Modules\SwissVat\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Periodic Swiss VAT return (Form 050 Effective / Form 051 Saldosteuersatz) with AFC boxes and quadratura report.
 *
 * @property int $fiscal_year
 * @property string $period_type
 * @property int $period_number
 * @property string $period_label
 * @property CarbonImmutable $period_from
 * @property CarbonImmutable $period_to
 * @property string $accounting_method
 * @property string $consideration_basis
 * @property string $currency
 * @property string $status
 * @property string $box_200_total_turnover
 * @property string $box_289_total_deductions
 * @property string $box_299_taxable_turnover
 * @property string $box_399_total_output_tax
 * @property string $box_479_total_input_tax
 * @property string $box_500_payable_amount
 * @property string $box_510_credit_amount
 * @property string $gross_turnover
 * @property string $invoiced_vat_total
 * @property string $forfeited_input_vat
 * @property string $sss_revenue_adjustment
 * @property string $bezugsteuer_base
 * @property string $bezugsteuer_tax
 * @property string $adjustments_box_410
 * @property string $adjustments_box_415
 * @property string $adjustments_box_420
 * @property array<int|string, mixed> $afc_boxes
 * @property bool $quadratura_passed
 * @property array<string, mixed>|null $quadratura_report
 * @property array<int, array<string, mixed>>|null $reconciliation_lines
 * @property CarbonImmutable|null $finalized_at
 * @property string|null $finalized_by
 * @property CarbonImmutable|null $submitted_at
 * @property string|null $suissetax_reference
 */
final class SwissVatReturn extends BaseEnterpriseModel
{
    protected $table = 'swiss_vat_returns';

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'period_number' => 'integer',
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'box_200_total_turnover' => 'decimal:2',
            'box_289_total_deductions' => 'decimal:2',
            'box_299_taxable_turnover' => 'decimal:2',
            'box_399_total_output_tax' => 'decimal:2',
            'box_479_total_input_tax' => 'decimal:2',
            'box_500_payable_amount' => 'decimal:2',
            'box_510_credit_amount' => 'decimal:2',
            'gross_turnover' => 'decimal:2',
            'invoiced_vat_total' => 'decimal:2',
            'forfeited_input_vat' => 'decimal:2',
            'sss_revenue_adjustment' => 'decimal:2',
            'bezugsteuer_base' => 'decimal:2',
            'bezugsteuer_tax' => 'decimal:2',
            'adjustments_box_410' => 'decimal:2',
            'adjustments_box_415' => 'decimal:2',
            'adjustments_box_420' => 'decimal:2',
            'afc_boxes' => 'array',
            'quadratura_passed' => 'boolean',
            'quadratura_report' => 'array',
            'reconciliation_lines' => 'array',
            'finalized_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<SwissVatXmlExport, $this>
     */
    public function xmlExports(): HasMany
    {
        return $this->hasMany(SwissVatXmlExport::class, 'swiss_vat_return_id');
    }

    public function isLocked(): bool
    {
        return in_array($this->status, ['finalized', 'submitted'], true);
    }
}
