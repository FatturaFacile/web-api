<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Persistence;

use App\Casts\DateOnly;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Treasury\Currency\Domain\Enums\RevaluationPeriodType;
use Modules\Treasury\Currency\Domain\Enums\RevaluationStatus;

/**
 * Period-end revaluation of the open items and currency accounts of a company (draft, posted, reversed).
 *
 * @property Carbon $period_end
 * @property RevaluationPeriodType $period_type
 * @property RevaluationStatus $status
 * @property string $functional_currency
 * @property list<string>|null $currencies
 * @property string $total_gain
 * @property string $total_loss
 * @property string $net_result
 * @property string $deferred_gain
 * @property string $reserve_amount
 * @property string|null $journal_entry_id
 * @property string|null $reversal_journal_entry_id
 * @property Carbon|null $reverse_on
 * @property Carbon|null $posted_at
 * @property Carbon|null $reversed_at
 * @property string|null $idempotency_key
 * @property string|null $created_by
 */
final class CurrencyFxRevaluation extends BaseEnterpriseModel
{
    protected $table = 'currency_fx_revaluations';

    protected function casts(): array
    {
        return [
            'period_end' => DateOnly::class,
            'period_type' => RevaluationPeriodType::class,
            'status' => RevaluationStatus::class,
            'currencies' => 'array',
            'total_gain' => 'decimal:2',
            'total_loss' => 'decimal:2',
            'net_result' => 'decimal:2',
            'deferred_gain' => 'decimal:2',
            'reserve_amount' => 'decimal:2',
            'reverse_on' => DateOnly::class,
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<CurrencyFxRevaluationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CurrencyFxRevaluationLine::class, 'revaluation_id')->orderBy('line_type')->orderBy('currency')->orderBy('reference');
    }
}
