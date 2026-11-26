<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Persistence;

use App\Casts\DateOnly;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\Treasury\Currency\Domain\Enums\FxDirection;
use Modules\Treasury\Currency\Domain\Enums\FxResult;

/**
 * The realised exchange difference of one settlement (a receipt or payment allocated to an open item), recorded once per
 * allocation.
 *
 * @property string $allocation_id
 * @property FxDirection $direction
 * @property string $currency
 * @property string $settled_amount
 * @property string $document_rate
 * @property string|null $payment_rate
 * @property string $document_value
 * @property string $payment_value
 * @property string $difference
 * @property string $effect
 * @property FxResult $result
 * @property Carbon $payment_date
 * @property string|null $journal_entry_id
 * @property bool $ledger_posted
 */
final class RealizedFxDifference extends BaseEnterpriseModel
{
    protected $table = 'currency_realized_fx_differences';

    protected function casts(): array
    {
        return [
            'direction' => FxDirection::class,
            'result' => FxResult::class,
            'settled_amount' => 'decimal:4',
            'document_rate' => 'decimal:6',
            'payment_rate' => 'decimal:6',
            'document_value' => 'decimal:2',
            'payment_value' => 'decimal:2',
            'difference' => 'decimal:2',
            'effect' => 'decimal:2',
            'payment_date' => DateOnly::class,
            'ledger_posted' => 'boolean',
        ];
    }
}
