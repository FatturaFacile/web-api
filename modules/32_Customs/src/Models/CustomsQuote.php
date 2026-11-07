<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Customs\Enums\QuoteStatus;

final class CustomsQuote extends CustomsRecord
{
    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'is_spot' => 'boolean',
            'discount_percent' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'valid_until' => 'date',
        ];
    }

    /**
     * @return HasMany<CustomsQuoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CustomsQuoteLine::class, 'quote_id');
    }

    /**
     * @return BelongsTo<CustomsDossier, $this>
     */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(CustomsDossier::class, 'dossier_id');
    }
}
