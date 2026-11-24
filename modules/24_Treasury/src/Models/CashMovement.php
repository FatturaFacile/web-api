<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A receipt (`in`) or payment (`out`) of a cash register, in cash or by cheque, posted against a counter account of
 * the chart of accounts.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $cash_register_id
 * @property Carbon $movement_date
 * @property string $direction
 * @property string $payment_method
 * @property string $amount
 * @property string $counter_account_id
 * @property string $description
 * @property string|null $reference
 * @property string $idempotency_key
 * @property string|null $journal_entry_id
 */
final class CashMovement extends BaseEnterpriseModel
{
    public const string In = 'in';

    public const string Out = 'out';

    protected $table = 'treasury_cash_movements';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'movement_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<CashRegister, $this>
     */
    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }
}
