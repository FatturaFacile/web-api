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
 * @property string|null $supplier_contract_id
 * @property string $rebate_type
 * @property string|null $fixed_bonus_target
 * @property string|null $fixed_bonus_amount
 * @property string $accrual_frequency
 * @property string|null $accrual_account_id
 * @property string|null $income_account_id
 * @property int $version
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

    /** Tiered volume rebate (Premio a scaglioni): the scale of tiers earns a percentage and/or a flat amount. */
    public const string TieredVolume = 'tiered_volume';

    /** Fixed bonus unlocked when the qualifying spend reaches the target. */
    public const string FixedBonus = 'fixed_bonus';

    /** Marketing, co-marketing and listing contributions planned by date, independent from the volume. */
    public const string MarketingContribution = 'marketing_contribution';

    /** @var list<string> */
    public const array RebateTypes = [self::TieredVolume, self::FixedBonus, self::MarketingContribution];

    /** @var list<string> */
    public const array AccrualFrequencies = ['monthly', 'quarterly', 'custom'];

    public const string Draft = 'draft';

    public const string Active = 'active';

    public const string Closed = 'closed';

    public const string Cancelled = 'cancelled';

    /** @var list<string> */
    public const array Statuses = [self::Draft, self::Active, self::Closed, self::Cancelled];

    protected $table = 'rebate_agreements';

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'fixed_bonus_target' => 'decimal:2', 'fixed_bonus_amount' => 'decimal:2', 'version' => 'integer'];
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

    /**
     * @return HasMany<RebateContribution, $this>
     */
    public function contributions(): HasMany
    {
        return $this->hasMany(RebateContribution::class, 'agreement_id')->orderBy('due_on');
    }

    /**
     * @return HasMany<RebateAccrualRun, $this>
     */
    public function accrualRuns(): HasMany
    {
        return $this->hasMany(RebateAccrualRun::class, 'agreement_id')->orderByDesc('period_end')->orderByDesc('created_at');
    }

    public function isSales(): bool
    {
        return $this->direction === self::Sales;
    }
}
