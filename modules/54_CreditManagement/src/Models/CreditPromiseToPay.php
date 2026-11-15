<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A promise to pay of a customer on a collection case.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $case_id
 * @property string $promised_amount
 * @property Carbon $promised_on
 * @property string $status
 * @property string|null $paid_amount
 * @property Carbon|null $resolved_on
 * @property string|null $recorded_by
 * @property string|null $notes
 */
final class CreditPromiseToPay extends BaseEnterpriseModel
{
    public const string Pending = 'pending';

    public const string Kept = 'kept';

    public const string Broken = 'broken';

    public const string Cancelled = 'cancelled';

    /** @var list<string> */
    public const array Statuses = [self::Pending, self::Kept, self::Broken, self::Cancelled];

    protected $table = 'credit_promises_to_pay';

    protected function casts(): array
    {
        return [
            'promised_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'promised_on' => 'date',
            'resolved_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<CreditCollectionCase, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(CreditCollectionCase::class, 'case_id');
    }
}
