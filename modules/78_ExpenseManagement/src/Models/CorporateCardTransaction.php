<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\ExpenseManagement\Domain\Enums\ReconciliationStatus;

/**
 * An imported statement line of a corporate card. It is matched when the expense lines linked to it cover its amount.
 *
 * @property string $card_account_id
 * @property string|null $external_id
 * @property string $merchant_name
 * @property Carbon $transaction_date
 * @property Carbon|null $posting_date
 * @property string $amount
 * @property string $currency
 * @property ReconciliationStatus $status
 * @property string $matched_amount
 */
final class CorporateCardTransaction extends BaseEnterpriseModel
{
    protected $table = 'exm_card_transactions';

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'posting_date' => 'date',
            'amount' => 'decimal:2',
            'status' => ReconciliationStatus::class,
            'matched_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<CorporateCardAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CorporateCardAccount::class, 'card_account_id');
    }

    /**
     * @return HasMany<ExpenseLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class, 'corporate_card_transaction_id');
    }
}
