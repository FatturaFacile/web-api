<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Persistence;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Treasury\Currency\Domain\Enums\FxResult;
use Modules\Treasury\Currency\Domain\Enums\RevaluationLineType;
use Modules\Treasury\Currency\Domain\Enums\UnrealizedGainTreatment;

/**
 * A line of a revaluation: one currency position (open receivables of a control account, open payables, a bank account, a
 * cash register) with its balance in the currency, its book value, the closing rate and the difference.
 *
 * @property string $revaluation_id
 * @property RevaluationLineType $line_type
 * @property string $currency
 * @property string|null $reference
 * @property string|null $account_id
 * @property string $currency_balance
 * @property string $book_value
 * @property string $closing_rate
 * @property string $revalued_value
 * @property string $difference
 * @property FxResult $result
 * @property UnrealizedGainTreatment $treatment
 */
final class CurrencyFxRevaluationLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'currency_fx_revaluation_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'line_type' => RevaluationLineType::class,
            'currency_balance' => 'decimal:4',
            'book_value' => 'decimal:2',
            'closing_rate' => 'decimal:6',
            'revalued_value' => 'decimal:2',
            'difference' => 'decimal:2',
            'result' => FxResult::class,
            'treatment' => UnrealizedGainTreatment::class,
        ];
    }

    /**
     * @return BelongsTo<CurrencyFxRevaluation, $this>
     */
    public function revaluation(): BelongsTo
    {
        return $this->belongsTo(CurrencyFxRevaluation::class, 'revaluation_id');
    }
}
