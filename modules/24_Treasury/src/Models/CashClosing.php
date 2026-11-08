<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The daily closing of a cash register: the balance the movements give, the cash counted and the difference, which is
 * posted to the cash difference account. A closed day is final.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $cash_register_id
 * @property Carbon $closing_date
 * @property string $opening_balance
 * @property string $total_in
 * @property string $total_out
 * @property string $expected_balance
 * @property string $counted_balance
 * @property string $difference
 * @property string|null $difference_journal_entry_id
 * @property string|null $notes
 */
final class CashClosing extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'treasury_cash_closings';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'closing_date' => 'date',
            'opening_balance' => 'decimal:2',
            'total_in' => 'decimal:2',
            'total_out' => 'decimal:2',
            'expected_balance' => 'decimal:2',
            'counted_balance' => 'decimal:2',
            'difference' => 'decimal:2',
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
