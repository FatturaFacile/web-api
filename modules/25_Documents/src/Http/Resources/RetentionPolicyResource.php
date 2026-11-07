<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class RetentionPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'document_type' => $this->document_type,
            'retention_months' => (int) $this->retention_months,
            'action' => $this->action,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
