<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CustomsDeclarationItem extends CustomsRecord
{
    protected function casts(): array
    {
        return [
            'gross_mass' => 'decimal:3',
            'net_mass' => 'decimal:3',
            'additional_unit' => 'decimal:1',
            'statistical_value' => 'decimal:2',
            'vat_value' => 'decimal:2',
            'duty_rate' => 'decimal:2',
            'preference' => 'boolean',
            'is_repair' => 'boolean',
            'produced_documents' => 'array',
        ];
    }

    /**
     * @return BelongsTo<CustomsDeclaration, $this>
     */
    public function declaration(): BelongsTo
    {
        return $this->belongsTo(CustomsDeclaration::class, 'declaration_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
