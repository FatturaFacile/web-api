<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Models\FxRevaluation;
use OpenApi\Attributes as OA;

/**
 * The period-end revaluation of a foreign-currency bank account and the entry of its unrealised difference.
 *
 * @mixin FxRevaluation
 */
#[OA\Schema(
    schema: 'TreasuryBankFxRevaluationResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'bank_account_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'period_end_date', type: 'string', format: 'date'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'closing_rate', description: 'Units of the currency per unit of the accounting currency', type: 'string'),
        new OA\Property(property: 'foreign_amount', type: 'string'),
        new OA\Property(property: 'book_value_base', type: 'string'),
        new OA\Property(property: 'revalued_market_base', type: 'string'),
        new OA\Property(property: 'unrealized_gain_loss', type: 'string'),
        new OA\Property(property: 'gl_journal_entry_id', type: 'string', format: 'uuid', nullable: true),
    ]
)]
final class FxRevaluationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bank_account_id' => $this->bank_account_id,
            'period_end_date' => CarbonImmutable::parse($this->period_end_date)->toDateString(),
            'currency' => $this->currency,
            'closing_rate' => $this->closing_rate === null ? null : (string) $this->closing_rate,
            'foreign_amount' => (string) $this->foreign_amount,
            'book_value_base' => (string) $this->book_value_base,
            'revalued_market_base' => (string) $this->revalued_market_base,
            'unrealized_gain_loss' => (string) $this->unrealized_gain_loss,
            'gl_journal_entry_id' => $this->gl_journal_entry_id,
        ];
    }
}
