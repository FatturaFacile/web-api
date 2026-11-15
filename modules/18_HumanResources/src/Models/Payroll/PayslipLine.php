<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models\Payroll;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HumanResources\Enums\Payroll\PayslipLineSide;
use Modules\HumanResources\Enums\Payroll\PayslipLineType;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $payslip_id
 * @property string|null $wage_type_id
 * @property string $code
 * @property string $label
 * @property PayslipLineType $line_type
 * @property PayslipLineSide $side
 * @property int $base_rappen
 * @property int|null $rate_ppm
 * @property int $amount_rappen
 * @property int $sort_order
 */
final class PayslipLine extends BaseEnterpriseModel
{
    protected $table = 'hr_ch_payslip_lines';

    /**
     * @return BelongsTo<Payslip, $this>
     */
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class, 'payslip_id');
    }

    protected function casts(): array
    {
        return [
            'line_type' => PayslipLineType::class,
            'side' => PayslipLineSide::class,
            'base_rappen' => 'integer',
            'rate_ppm' => 'integer',
            'amount_rappen' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
