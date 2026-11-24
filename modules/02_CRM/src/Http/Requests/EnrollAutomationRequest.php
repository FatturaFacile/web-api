<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

/**
 * Enrols exactly one contact or one lead in a sequence.
 */
final class EnrollAutomationRequest extends CrmExtensionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'contact_id' => ['nullable', 'uuid', 'required_without:lead_id', 'prohibits:lead_id', $this->ownRow('contacts')],
            'lead_id' => ['nullable', 'uuid', 'required_without:contact_id', 'prohibits:contact_id', $this->ownRow('crm_leads')],
        ];
    }
}
