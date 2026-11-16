<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Contact
 */
class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'type' => $this->type,
            'legal_type' => $this->legal_type,
            'country' => $this->country,
            'company_name' => $this->company_name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'vat_number' => $this->vat_number,
            'fiscal_code' => $this->fiscal_code,
            'uid_number' => $this->uid_number,
            'sdi_code' => $this->sdi_code,
            'pec' => $this->pec,
            'document_language' => $this->document_language,
            'iban' => $this->iban,
            'qr_iban' => $this->qr_iban,
            'payment_terms_days' => $this->payment_terms_days,
            'addresses' => ContactAddressResource::collection($this->whenLoaded('addresses')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
