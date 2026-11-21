<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Contact;
use App\Models\User;
use App\Services\Security\PiiMasker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Contact
 */
class ContactResource extends JsonResource
{
    /**
     * Dynamic data masking: the clear value only for callers holding privacy.pii.unmask.
     */
    private function maskedFor(Request $request, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $user = $request->user();
        $tenantId = $this->tenant_id !== null ? (string) $this->tenant_id : null;

        if ($user instanceof User && $user->hasTenantPermission($tenantId, PiiMasker::PERMISSION)) {
            return $value;
        }

        return (new PiiMasker)->iban($value);
    }

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
            'eori_number' => $this->eori_number,
            'default_incoterm' => $this->default_incoterm,
            'sdi_code' => $this->sdi_code,
            'pec' => $this->pec,
            'document_language' => $this->document_language,
            'iban' => $this->maskedFor($request, $this->iban),
            'qr_iban' => $this->maskedFor($request, $this->qr_iban),
            'payment_terms_days' => $this->payment_terms_days,
            'parent_id' => $this->parent_id,
            'hierarchy_type' => $this->hierarchy_type,
            'fiscal_verified_at' => $this->fiscal_verified_at?->toISOString(),
            'fiscal_verification_source' => $this->fiscal_verification_source,
            'addresses' => ContactAddressResource::collection($this->whenLoaded('addresses')),
            'persons' => ContactPersonResource::collection($this->whenLoaded('persons')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
