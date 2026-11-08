<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\GeneralLedger\Models\ChartOfAccount;

/**
 * A cash register (prima nota cassa): cash and cheques received or paid, posted to its ledger account, and closed day
 * by day against the counted cash. No movement can be recorded on or before the last closed day.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $code
 * @property string $name
 * @property string $currency
 * @property string $gl_account_id
 * @property string $opening_balance
 * @property string $current_balance
 * @property Carbon|null $last_closed_date
 * @property bool $is_active
 */
final class CashRegister extends BaseEnterpriseModel
{
    protected $table = 'treasury_cash_registers';

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'last_closed_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'gl_account_id');
    }

    /**
     * @return HasMany<CashMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class, 'cash_register_id');
    }

    /**
     * @return HasMany<CashClosing, $this>
     */
    public function closings(): HasMany
    {
        return $this->hasMany(CashClosing::class, 'cash_register_id');
    }
}
