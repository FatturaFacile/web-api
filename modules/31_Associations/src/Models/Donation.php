<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A gift of money or in kind, possibly for a campaign, posted to the ledger when it is money.
 *
 * @property string $id
 * @property ?string $donor_id
 * @property ?string $campaign_id
 * @property string $kind
 * @property string $amount
 * @property ?Donor $donor
 * @property ?Campaign $campaign
 */
final class Donation extends AssociationRecord
{
    public const string Money = 'money';

    public const string InKind = 'in_kind';

    protected $table = 'assoc_donations';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'received_on' => 'date', 'in_kind_description' => 'encrypted'];
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class, 'donor_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(DonationReceipt::class, 'donation_id');
    }
}
