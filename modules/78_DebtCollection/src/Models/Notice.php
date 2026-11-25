<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A dunning notice, messa in mora or reminder sent to a customer for one or more open items.
 *
 * @property string $number
 * @property string $kind
 * @property int $level
 * @property string $customer_id
 * @property string $jurisdiction
 * @property string $currency
 * @property string $principal
 * @property string $interest
 * @property string $fee
 * @property string $total
 * @property Carbon $issued_on
 * @property Carbon|null $new_due_date
 * @property string $channel
 * @property string $status
 * @property string $locale
 * @property string $subject
 * @property string|null $body
 * @property string|null $batch_id
 * @property string|null $pec_message_id
 * @property Carbon|null $sent_at
 * @property string|null $error
 * @property int $attempts
 * @property string|null $charge_receivable_item_id
 * @property string|null $created_by
 */
final class Notice extends BaseEnterpriseModel
{
    protected $table = 'dc_notices';

    protected function casts(): array
    {
        return ['principal' => 'decimal:2', 'interest' => 'decimal:2', 'fee' => 'decimal:2', 'total' => 'decimal:2', 'issued_on' => 'date', 'new_due_date' => 'date', 'sent_at' => 'datetime', 'level' => 'integer', 'attempts' => 'integer'];
    }

    /**
     * @return HasMany<NoticeLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(NoticeLine::class, 'notice_id')->orderBy('due_date');
    }

    /**
     * @return HasMany<PecReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(PecReceipt::class, 'notice_id')->orderBy('received_at');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }
}
