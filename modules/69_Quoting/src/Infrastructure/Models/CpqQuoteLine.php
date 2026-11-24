<?php

declare(strict_types=1);

namespace Modules\Quoting\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Quoting\Domain\Enums\LineSelectionMode;
use Modules\Quoting\Domain\Enums\QuoteLineType;

/**
 * @property string $id
 * @property string $quote_id
 * @property string|null $section_id
 * @property string|null $origin_id
 * @property QuoteLineType $line_type
 * @property LineSelectionMode $selection_mode
 * @property string|null $alternative_group
 * @property bool $is_default_selected
 * @property string $description
 * @property string $quantity
 * @property string $unit_price
 * @property string $unit_cost
 * @property string $discount_percent
 * @property string $discount_fixed
 * @property string $tax_rate
 * @property array<int, array<string, mixed>>|null $tier_prices
 * @property int $sort_order
 */
final class CpqQuoteLine extends BaseEnterpriseModel
{
    use GuardsFrozenQuote;

    protected $table = 'cpq_quote_lines';

    protected function casts(): array
    {
        return [
            'line_type' => QuoteLineType::class,
            'selection_mode' => LineSelectionMode::class,
            'is_default_selected' => 'boolean',
            'tier_prices' => 'array',
            'sort_order' => 'integer',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'discount_percent' => 'decimal:4',
            'discount_fixed' => 'decimal:4',
            'tax_rate' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<CpqQuote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(CpqQuote::class, 'quote_id');
    }
}
