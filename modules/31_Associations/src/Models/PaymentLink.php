<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request for online payment of an instalment at a provider.
 */
final class PaymentLink extends AssociationRecord
{
    public const string Pending = 'pending';

    public const string Paid = 'paid';

    public const string Failed = 'failed';

    protected $table = 'assoc_payment_links';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'expires_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function instalment(): BelongsTo
    {
        return $this->belongsTo(FeeInstalment::class, 'fee_instalment_id');
    }
}
