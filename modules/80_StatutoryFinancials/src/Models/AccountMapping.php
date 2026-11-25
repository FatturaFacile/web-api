<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * Chart of accounts -> statement line rule, by account code prefix (the longest prefix wins, a tenant rule beats a global one).
 * A bifronte account carries a second line for the credit side: debit balances go to `line_code`, credit balances to `alt_line_code`.
 *
 * @property string $family
 * @property string $code_prefix
 * @property string $line_code
 * @property string|null $alt_line_code
 * @property bool $is_bifront
 * @property int $priority
 * @property string|null $notes
 */
final class AccountMapping extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'sfs_account_mappings';

    protected function casts(): array
    {
        return [
            'is_bifront' => 'boolean',
            'priority' => 'integer',
        ];
    }
}
