<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A payment plan (piano di rientro) agreed with a customer; while active it suspends dunning.
 *
 * @property string $customer_id
 * @property string $currency
 * @property string $total_amount
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string $status
 * @property list<string>|null $receivable_item_ids
 * @property list<array{due_date: string, amount: string}>|null $installments
 * @property string|null $notes
 */
final class PaymentPlan extends BaseEnterpriseModel
{
    protected $table = 'dc_payment_plans';

    protected function casts(): array
    {
        return ['total_amount' => 'decimal:2', 'start_date' => 'date', 'end_date' => 'date', 'receivable_item_ids' => 'array', 'installments' => 'array'];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }
}
