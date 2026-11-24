<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * The decision of one internal user on a bank detail change request.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $request_id
 * @property string $user_id
 * @property string $decision
 * @property string|null $comment
 * @property Carbon $decided_at
 */
final class BankChangeApproval extends BaseEnterpriseModel
{
    public const string Approved = 'approved';

    public const string Rejected = 'rejected';

    protected $table = 'sp_bank_change_approvals';

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }
}
