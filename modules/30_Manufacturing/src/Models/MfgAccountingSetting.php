<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Ledger accounts of the manufacturing postings of a tenant. A missing code falls back to the module default
 * (see ManufacturingAccountingService::DefaultAccounts).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $raw_material_account_code
 * @property string|null $wip_account_code
 * @property string|null $finished_goods_account_code
 * @property string|null $labor_absorbed_account_code
 * @property string|null $overhead_absorbed_account_code
 * @property string|null $subcontract_accrual_account_code
 * @property string|null $material_variance_account_code
 * @property string|null $labor_variance_account_code
 * @property string|null $overhead_variance_account_code
 * @property string|null $subcontract_variance_account_code
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgAccountingSetting extends BaseEnterpriseModel
{
    /** The account roles a tenant can configure. */
    public const array AccountRoles = [
        'raw_material',
        'wip',
        'finished_goods',
        'labor_absorbed',
        'overhead_absorbed',
        'subcontract_accrual',
        'material_variance',
        'labor_variance',
        'overhead_variance',
        'subcontract_variance',
    ];

    protected $table = 'mfg_accounting_settings';
}
