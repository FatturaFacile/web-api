<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * One step of the lifecycle of a contract: from which status to which, why and by whom. Never edited, it is the history
 * shown on the contract.
 */
final class ContractStatusChange extends BaseEnterpriseModel
{
    protected $table = 'contract_status_changes';
}
