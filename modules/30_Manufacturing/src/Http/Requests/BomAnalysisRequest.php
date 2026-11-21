<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Query parameters of the BOM analyses: explosion (quantity, levels, phantom flattening), where-used and the
 * preferential origin test (date, threshold).
 */
final class BomAnalysisRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['nullable', ...self::PositiveQuantity],
            'date' => ['nullable', 'date'],
            'multi_level' => ['nullable', 'boolean'],
            'flatten_phantoms' => ['nullable', 'boolean'],
            'threshold_percentage' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
        ];
    }
}
