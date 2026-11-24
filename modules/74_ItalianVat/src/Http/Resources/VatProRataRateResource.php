<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ItalianVat\Models\VatProRataRate;

/**
 * @mixin VatProRataRate
 */
final class VatProRataRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'year' => $this->year,
            'deductible_operations' => (string) $this->deductible_operations,
            'total_operations' => (string) $this->total_operations,
            'provisional_percentage' => $this->provisional_percentage,
            'definitive_percentage' => $this->definitive_percentage,
            'is_definitive' => $this->is_definitive,
        ];
    }
}
