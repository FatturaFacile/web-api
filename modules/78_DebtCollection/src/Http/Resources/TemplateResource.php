<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DebtCollection\Models\Template;

/**
 * @mixin Template
 */
final class TemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'locale' => $this->locale,
            'jurisdiction' => $this->jurisdiction,
            'subject' => $this->subject,
            'body' => $this->body,
            'is_global' => $this->tenant_id === null,
        ];
    }
}
