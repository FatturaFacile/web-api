<?php

declare(strict_types=1);

namespace Modules\Returns\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The return rules of a company: how many days after the invoice a customer can still return goods,
 * warranty window, restocking fees, advance replacement SLA, and B-stock devaluation defaults.
 *
 * @property string $id
 * @property string $tenant_id
 * @property int $return_window_days
 * @property int $warranty_window_days
 * @property string $default_restocking_fee_percent
 * @property int $advance_replacement_sla_days
 * @property string $outlet_devaluation_grade_b_percent
 * @property string $outlet_devaluation_grade_c_percent
 * @property string|null $outlet_warehouse_id
 */
final class ReturnsSetting extends BaseEnterpriseModel
{
    public const int DefaultWindowDays = 30;

    public const int DefaultWarrantyWindowDays = 730;

    public const string DefaultRestockingFeePercent = '10.00';

    public const int DefaultAdvanceReplacementSlaDays = 15;

    public const string DefaultDevaluationGradeBPercent = '20.00';

    public const string DefaultDevaluationGradeCPercent = '35.00';

    protected $table = 'returns_settings';

    protected function casts(): array
    {
        return [
            'return_window_days' => 'integer',
            'warranty_window_days' => 'integer',
            'default_restocking_fee_percent' => 'decimal:2',
            'advance_replacement_sla_days' => 'integer',
            'outlet_devaluation_grade_b_percent' => 'decimal:2',
            'outlet_devaluation_grade_c_percent' => 'decimal:2',
        ];
    }
}
