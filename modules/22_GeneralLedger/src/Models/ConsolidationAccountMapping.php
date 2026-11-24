<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Maps an account of the chart of a member entity to an account code of the group chart. Without a mapping an account
 * enters the consolidation under its own code (the first version of the consolidation). The type of the account is
 * always the one of the source account: the mapping renames, it does not reclassify, because the translation rate
 * depends on the type.
 *
 * @property string $id
 * @property string $group_id
 * @property string $member_tenant_id
 * @property string $account_id
 * @property string $group_account_code
 * @property string|null $group_account_name
 */
final class ConsolidationAccountMapping extends BaseEnterpriseModel
{
    protected $table = 'gl_consolidation_account_mappings';
}
