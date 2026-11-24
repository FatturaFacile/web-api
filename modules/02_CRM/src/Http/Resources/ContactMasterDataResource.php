<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The master data of a contact as embedded in CRM payloads (accounts, conversions, duplicates). CRM shows these
 * values from the party master instead of keeping its own copy, so a correction made on the contact is seen
 * everywhere.
 *
 * @mixin Contact
 */
final class ContactMasterDataResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'company_name' => $this->company_name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'display_name' => $this->company_name ?: trim("{$this->first_name} {$this->last_name}"),
            'vat_number' => $this->vat_number,
            'fiscal_code' => $this->fiscal_code,
            'email' => $this->email,
            'phone' => $this->phone,
            'pec' => $this->pec,
            'sdi_code' => $this->sdi_code,
            'country' => $this->country,
        ];
    }
}
