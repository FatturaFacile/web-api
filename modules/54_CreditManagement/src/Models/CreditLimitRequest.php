<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A request to change the credit limit of a customer, decided by an approver other than the requester.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $customer_id
 * @property string $current_limit
 * @property string $requested_limit
 * @property string|null $proposed_limit
 * @property string|null $approved_limit
 * @property string $currency
 * @property string $status
 * @property string|null $score_id
 * @property string|null $reason
 * @property string|null $requested_by
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon|null $valid_until
 */
final class CreditLimitRequest extends BaseEnterpriseModel
{
    public const string Pending = 'pending';

    public const string Approved = 'approved';

    public const string Rejected = 'rejected';

    public const string Cancelled = 'cancelled';

    /** @var list<string> */
    public const array Statuses = [self::Pending, self::Approved, self::Rejected, self::Cancelled];

    protected $table = 'credit_limit_requests';

    protected function casts(): array
    {
        return [
            'current_limit' => 'decimal:2',
            'requested_limit' => 'decimal:2',
            'proposed_limit' => 'decimal:2',
            'approved_limit' => 'decimal:2',
            'decided_at' => 'datetime',
            'valid_until' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }
}
