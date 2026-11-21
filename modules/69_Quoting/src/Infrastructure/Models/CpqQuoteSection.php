<?php

declare(strict_types=1);

namespace Modules\Quoting\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $quote_id
 * @property string|null $origin_id
 * @property string $title
 * @property string $discount_percent
 * @property int $sort_order
 */
final class CpqQuoteSection extends BaseEnterpriseModel
{
    use GuardsFrozenQuote;

    protected $table = 'cpq_quote_sections';

    protected function casts(): array
    {
        return ['page_break_before' => 'boolean', 'sort_order' => 'integer', 'discount_percent' => 'decimal:4'];
    }

    /**
     * @return BelongsTo<CpqQuote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(CpqQuote::class, 'quote_id');
    }
}
