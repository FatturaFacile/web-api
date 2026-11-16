<?php

declare(strict_types=1);

namespace Modules\Rebates\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A volume rebate agreement with a customer (sales: the company owes the rebate) or a supplier (purchases: the supplier
 * owes it). The rebate follows the tiers of the invoiced volume of the agreement period.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $direction
 * @property string $contact_id
 * @property string $tier_mode
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $currency
 * @property string $status
 * @property string|null $notes
 */
final class RebateAgreement extends BaseEnterpriseModel
{
    public const string Sales = 'sales';

    public const string Purchase = 'purchase';

    /** @var list<string> */
    public const array Directions = [self::Sales, self::Purchase];

    /** The rate of the highest tier reached applies to the whole volume. */
    public const string Retroactive = 'retroactive';

    /** Every band of volume earns the rate of its own tier. */
    public const string Incremental = 'incremental';

    /** @var list<string> */
    public const array TierModes = [self::Retroactive, self::Incremental];

    public const string Draft = 'draft';

    public const string Active = 'active';

    public const string Closed = 'closed';

    public const string Cancelled = 'cancelled';

    /** @var list<string> */
    public const array Statuses = [self::Draft, self::Active, self::Closed, self::Cancelled];

    protected $table = 'rebate_agreements';

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * @return HasMany<RebateTier, $this>
     */
    public function tiers(): HasMany
    {
        return $this->hasMany(RebateTier::class, 'agreement_id')->orderBy('threshold_amount');
    }

    /**
     * @return HasMany<RebateAccrual, $this>
     */
    public function accruals(): HasMany
    {
        return $this->hasMany(RebateAccrual::class, 'agreement_id')->orderBy('as_of')->orderBy('created_at');
    }

    /**
     * @return HasMany<RebateSettlement, $this>
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(RebateSettlement::class, 'agreement_id')->orderByDesc('settled_on');
    }

    public function isSales(): bool
    {
        return $this->direction === self::Sales;
    }
}
