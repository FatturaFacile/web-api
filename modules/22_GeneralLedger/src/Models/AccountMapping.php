<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Multi-standard account mapping linking an account in one Chart of Accounts/standard
 * to a target account or classification line in another Chart of Accounts/standard.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $company_id
 * @property string $source_account_id
 * @property string|null $target_account_id
 * @property string $source_standard
 * @property string $target_standard
 * @property string $target_account_code
 * @property string $target_account_name
 * @property string $mapping_type
 * @property bool $is_bidirectional
 * @property string $allocation_percentage
 * @property string|null $notes
 * @property bool $is_active
 * @property-read ChartOfAccount|null $sourceAccount
 * @property-read ChartOfAccount|null $targetAccount
 */
final class AccountMapping extends BaseEnterpriseModel
{
    use BelongsToCompany;

    protected $table = 'gl_account_mappings';

    protected function casts(): array
    {
        return [
            'is_bidirectional' => 'boolean',
            'is_active' => 'boolean',
            'allocation_percentage' => 'decimal:4',
        ];
    }

    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'source_account_id');
    }

    public function targetAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'target_account_id');
    }
}
