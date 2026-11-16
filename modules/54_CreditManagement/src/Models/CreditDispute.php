<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A dispute raised by a customer on an invoice, with its resolution.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $customer_id
 * @property string|null $case_id
 * @property string|null $invoice_id
 * @property string $disputed_amount
 * @property string $reason_code
 * @property string $status
 * @property Carbon $opened_on
 * @property Carbon|null $resolved_on
 * @property string|null $credited_amount
 * @property string|null $description
 * @property string|null $resolution_note
 */
final class CreditDispute extends BaseEnterpriseModel
{
    public const string Open = 'open';

    public const string UnderReview = 'under_review';

    public const string Upheld = 'upheld';

    public const string Rejected = 'rejected';

    /** @var list<string> */
    public const array Statuses = [self::Open, self::UnderReview, self::Upheld, self::Rejected];

    /** @var list<string> */
    public const array OpenStatuses = [self::Open, self::UnderReview];

    /** @var list<string> */
    public const array ReasonCodes = ['pricing', 'quality', 'delivery', 'duplicate', 'other'];

    protected $table = 'credit_disputes';

    protected function casts(): array
    {
        return [
            'disputed_amount' => 'decimal:2',
            'credited_amount' => 'decimal:2',
            'opened_on' => 'date',
            'resolved_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    /**
     * @return BelongsTo<CreditCollectionCase, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(CreditCollectionCase::class, 'case_id');
    }
}
