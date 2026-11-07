<?php

declare(strict_types=1);

namespace Modules\Visitors\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Visitors\Models\Visitor;

/**
 * A visitor with decrypted personal data. The identity document number never leaves the API: only whether one is on file.
 *
 * @mixin Visitor
 */
final class VisitorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'company_name' => $this->company_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'has_document' => $this->document_number !== null && $this->document_number !== '',
            'last_visit_at' => $this->last_visit_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
