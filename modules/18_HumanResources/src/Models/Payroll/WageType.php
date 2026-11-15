<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models\Payroll;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\HumanResources\Enums\Payroll\WageTypeCategory;

/**
 * Swiss wage type (Lohnart) with its social insurance subjection flags and ELM mapping code.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $code
 * @property string $name
 * @property WageTypeCategory $category
 * @property bool $is_ahv_subject
 * @property bool $is_alv_subject
 * @property bool $is_bvg_subject
 * @property bool $is_uvg_subject
 * @property bool $is_qst_subject
 * @property string|null $elm_code
 * @property bool $is_active
 * @property int $sort_order
 */
final class WageType extends BaseEnterpriseModel
{
    protected $table = 'hr_ch_wage_types';

    public function isSharedLookup(): bool
    {
        return true;
    }

    public function isSystemDefault(): bool
    {
        return $this->tenant_id === null;
    }

    protected function casts(): array
    {
        return [
            'category' => WageTypeCategory::class,
            'is_ahv_subject' => 'boolean',
            'is_alv_subject' => 'boolean',
            'is_bvg_subject' => 'boolean',
            'is_uvg_subject' => 'boolean',
            'is_qst_subject' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'translations' => 'array',
        ];
    }
}
