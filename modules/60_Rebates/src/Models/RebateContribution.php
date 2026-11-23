<?php

declare(strict_types=1);

namespace Modules\Rebates\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A marketing, co-marketing or catalogue listing contribution the supplier owes on a date, independent from the volume.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $agreement_id
 * @property string $kind
 * @property string $description
 * @property string $amount
 * @property Carbon $due_on
 * @property string $status
 * @property string|null $accrual_id
 */
final class RebateContribution extends BaseEnterpriseModel
{
    public const string Marketing = 'marketing';

    public const string CoMarketing = 'co_marketing';

    public const string Listing = 'listing';

    /** @var list<string> */
    public const array Kinds = [self::Marketing, self::CoMarketing, self::Listing];

    public const string Planned = 'planned';

    public const string Accrued = 'accrued';

    public const string Settled = 'settled';

    public const string Cancelled = 'cancelled';

    /** @var list<string> */
    public const array Statuses = [self::Planned, self::Accrued, self::Settled, self::Cancelled];

    protected $table = 'rebate_contributions';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'due_on' => 'date'];
    }

    /**
     * @return BelongsTo<RebateAgreement, $this>
     */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(RebateAgreement::class, 'agreement_id');
    }
}
