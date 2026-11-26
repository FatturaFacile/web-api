<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencyFxRevaluation;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencyFxRevaluationLine;

/**
 * A period-end revaluation; the lines are included when they were loaded. Amounts are decimal strings, dates `Y-m-d`.
 *
 * @mixin CurrencyFxRevaluation
 */
final class FxRevaluationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => (string) $this->resource->getKey(),
            'status' => $this->resource->status->value,
            'period_end' => $this->resource->period_end->toDateString(),
            'period_type' => $this->resource->period_type->value,
            'functional_currency' => $this->resource->functional_currency,
            'currencies' => $this->resource->currencies,
            'total_gain' => (string) $this->resource->total_gain,
            'total_loss' => (string) $this->resource->total_loss,
            'net_result' => (string) $this->resource->net_result,
            'deferred_gain' => (string) $this->resource->deferred_gain,
            'reserve_amount' => (string) $this->resource->reserve_amount,
            'journal_entry_id' => $this->resource->journal_entry_id,
            'reversal_journal_entry_id' => $this->resource->reversal_journal_entry_id,
            'reverse_on' => $this->resource->reverse_on?->toDateString(),
            'posted_at' => $this->resource->posted_at?->toIso8601String(),
            'reversed_at' => $this->resource->reversed_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];

        if ($this->resource->relationLoaded('lines')) {
            $data['lines'] = $this->resource->lines->map(static fn (CurrencyFxRevaluationLine $line): array => [
                'id' => (string) $line->getKey(),
                'line_type' => $line->line_type->value,
                'currency' => $line->currency,
                'reference' => $line->reference,
                'currency_balance' => (string) $line->currency_balance,
                'book_value' => (string) $line->book_value,
                'closing_rate' => (string) $line->closing_rate,
                'revalued_value' => (string) $line->revalued_value,
                'difference' => (string) $line->difference,
                'result' => $line->result->value,
                'treatment' => $line->treatment->value,
            ])->values()->all();
        }

        return $data;
    }
}
