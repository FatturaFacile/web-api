<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\GeneralLedger\Models\JournalEntry;

/**
 * A bank movement originated by treasury and posted to the ledger: an internal transfer between two bank accounts of
 * the tenant (both accounts posted), a bank fee, or interest earned or charged. The idempotency key makes a repeated
 * request return the movement already recorded.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $movement_type
 * @property string $bank_account_id
 * @property string|null $counter_bank_account_id
 * @property string $amount
 * @property string $currency
 * @property Carbon $movement_date
 * @property string|null $description
 * @property string $idempotency_key
 * @property string|null $journal_entry_id
 * @property string|null $created_by
 * @property Carbon|null $created_at
 */
final class TreasuryBankMovement extends BaseEnterpriseModel
{
    public const string TypeTransfer = 'transfer';

    public const string TypeBankFee = 'bank_fee';

    public const string TypeInterestIncome = 'interest_income';

    public const string TypeInterestExpense = 'interest_expense';

    /** @var list<string> */
    public const array ChargeTypes = [self::TypeBankFee, self::TypeInterestIncome, self::TypeInterestExpense];

    protected $table = 'treasury_bank_movements';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'movement_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function counterBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'counter_bank_account_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
