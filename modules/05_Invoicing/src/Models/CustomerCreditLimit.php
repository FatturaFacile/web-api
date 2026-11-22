<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use App\Models\Contact;
use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Credit configuration of a customer for a validity period: granted limit, insured limit with its policy, overdue
 * tolerance and the reaction when the customer goes over.
 *
 * @property string $id
 * @property string $customer_id
 * @property string $currency
 * @property string $granted_limit
 * @property string $insured_limit
 * @property string|null $insurer_name
 * @property string|null $policy_number
 * @property string $coverage_percentage
 * @property int|null $max_overdue_days
 * @property string $overdue_tolerance_amount
 * @property string $reaction_policy
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property bool $is_active
 * @property string|null $notes
 */
final class CustomerCreditLimit extends BaseEnterpriseModel
{
    public const string ReactionWarning = 'warning';

    public const string ReactionBlockOrder = 'block_order';

    public const string ReactionBlockDeliveryNote = 'block_delivery_note';

    public const string ReactionCreditHold = 'credit_hold';

    public const array Reactions = [self::ReactionWarning, self::ReactionBlockOrder, self::ReactionBlockDeliveryNote, self::ReactionCreditHold];

    protected $table = 'customer_credit_limits';

    protected function casts(): array
    {
        return [
            'granted_limit' => 'decimal:2',
            'insured_limit' => 'decimal:2',
            'coverage_percentage' => 'decimal:2',
            'overdue_tolerance_amount' => 'decimal:2',
            'max_overdue_days' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
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
     * Limits in force on `$date`.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEffectiveOn(Builder $query, CarbonInterface $date): Builder
    {
        $day = $date->toDateString();

        return $query->where('is_active', true)
            ->whereDate('valid_from', '<=', $day)
            ->where(static fn (Builder $inner) => $inner->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day));
    }

    /**
     * The part of the granted limit covered by the credit insurance: the insured limit times the coverage.
     */
    public function insuredCover(): string
    {
        return bcdiv(bcmul((string) $this->insured_limit, (string) $this->coverage_percentage, 4), '100', 2);
    }
}
