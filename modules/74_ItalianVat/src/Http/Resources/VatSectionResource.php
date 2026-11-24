<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ItalianVat\Models\VatSection;

/**
 * @mixin VatSection
 */
final class VatSectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'register_type' => $this->register_type,
            'code' => $this->code,
            'name' => $this->name,
            'document_kind' => $this->document_kind,
            'is_active' => $this->is_active,
        ];
    }
}
