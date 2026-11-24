<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Procurement\Models\Supplier;

/**
 * A request to change the bank details of a supplier, from the portal or entered internally. Anti-fraud: the change is
 * applied only after a callback verification and the approval of two different internal users, none of them the
 * requester.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $supplier_id
 * @property string $source
 * @property string|null $requested_by
 * @property string $account_holder
 * @property string $iban
 * @property string|null $bic
 * @property string|null $bank_name
 * @property string|null $previous_iban
 * @property string|null $reason
 * @property string $status
 * @property Carbon|null $callback_verified_at
 * @property string|null $callback_verified_by
 * @property string|null $callback_note
 * @property Carbon|null $decided_at
 * @property Carbon|null $applied_at
 * @property string|null $rejection_reason
 * @property Supplier|null $supplier
 */
final class BankChangeRequest extends BaseEnterpriseModel
{
    public const string Pending = 'pending';

    public const string Applied = 'applied';

    public const string Rejected = 'rejected';

    public const string Cancelled = 'cancelled';

    public const array Statuses = [self::Pending, self::Applied, self::Rejected, self::Cancelled];

    protected $table = 'sp_bank_change_requests';

    protected function casts(): array
    {
        return [
            'callback_verified_at' => 'datetime',
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<BankChangeApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(BankChangeApproval::class, 'request_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
