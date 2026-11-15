<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

final class TransitionVendorClaimRequest extends WarrantyRmaFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to' => ['required', 'string', 'in:submitted,acknowledged,approved_credit_note,approved_replacement,rejected,closed'],
            'credit_note_reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
