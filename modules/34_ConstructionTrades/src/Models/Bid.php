<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Models;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A bid for a job: positions with quantities and prices, printed as a tender specification, then billed partially from
 * the bid and finally from the approved settlement of the measured quantities.
 *
 * @property string $id
 * @property ?string $tenant_id
 * @property ?string $construction_site_id
 * @property string $contact_id
 * @property string $bid_number
 * @property string $title
 * @property string $language
 * @property string $currency
 * @property string $tax_rate
 * @property string $discount_percentage
 * @property string $retention_percentage
 * @property string $labour_hour_rate
 * @property string $status
 * @property ?Carbon $settlement_approved_at
 */
final class Bid extends TradesRecord
{
    /**
     * @var array<string, list<string>>
     */
    public const array Transitions = [
        'draft' => ['submitted', 'rejected'],
        'submitted' => ['accepted', 'rejected', 'draft'],
        'accepted' => [],
        'rejected' => ['draft'],
    ];

    protected $table = 'ct_bids';

    protected function casts(): array
    {
        return [
            'tax_rate' => 'decimal:2', 'discount_percentage' => 'decimal:2', 'retention_percentage' => 'decimal:2', 'labour_hour_rate' => 'decimal:2',
            'valid_until' => 'date', 'settlement_approved_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<BidPosition, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(BidPosition::class, 'bid_id')->orderBy('sort_order')->orderBy('position_code');
    }

    /**
     * @return HasMany<Billing, $this>
     */
    public function billings(): HasMany
    {
        return $this->hasMany(Billing::class, 'bid_id')->orderBy('sequence');
    }

    /**
     * @return HasMany<Measurement, $this>
     */
    public function measurements(): HasMany
    {
        return $this->hasMany(Measurement::class, 'bid_id');
    }

    /**
     * @return HasMany<SiteCost, $this>
     */
    public function siteCosts(): HasMany
    {
        return $this->hasMany(SiteCost::class, 'bid_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
