<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * An expense category with its fiscal flags and default ledger / VAT mapping. Rows without a tenant are global.
 *
 * @property string $code
 * @property string|null $name
 * @property bool $is_meal
 * @property bool $is_lodging
 * @property bool $is_transport
 * @property bool $is_mileage
 * @property bool $vat_recoverable
 * @property bool $requires_receipt
 * @property string|null $default_gl_account_code
 * @property string|null $default_vat_code
 * @property string $default_vat_rate
 * @property int $sort_order
 * @property bool $is_active
 */
final class ExpenseCategory extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'exm_categories';

    protected function casts(): array
    {
        return [
            'is_meal' => 'boolean',
            'is_lodging' => 'boolean',
            'is_transport' => 'boolean',
            'is_mileage' => 'boolean',
            'vat_recoverable' => 'boolean',
            'requires_receipt' => 'boolean',
            'default_vat_rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function nameKey(): string
    {
        return 'expenseManagement.category.'.$this->code;
    }
}
