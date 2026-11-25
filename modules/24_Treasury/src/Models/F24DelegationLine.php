<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $f24_delegation_id
 * @property string $section
 * @property string $tax_code
 * @property string|null $institution_code
 * @property string|null $reference_number
 * @property string|null $installment
 * @property string|null $reference_period
 * @property string|null $reference_year
 * @property string $debit_amount
 * @property string $credit_amount
 * @property int $position
 */
final class F24DelegationLine extends BaseEnterpriseModel
{
    public const SECTION_ERARIO = 'erario';

    public const SECTION_INPS = 'inps';

    public const SECTION_REGIONI = 'regioni';

    public const SECTION_IMU = 'imu';

    public const SECTION_INAIL = 'inail';

    /**
     * @var list<string>
     */
    public const SECTIONS = [self::SECTION_ERARIO, self::SECTION_INPS, self::SECTION_REGIONI, self::SECTION_IMU, self::SECTION_INAIL];

    protected $table = 'f24_delegation_lines';

    protected function casts(): array
    {
        return [
            'debit_amount' => 'decimal:2',
            'credit_amount' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<F24Delegation, $this>
     */
    public function delegation(): BelongsTo
    {
        return $this->belongsTo(F24Delegation::class, 'f24_delegation_id');
    }
}
