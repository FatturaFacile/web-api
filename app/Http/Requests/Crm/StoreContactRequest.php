<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Contact::class) ?? true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:client,customer,supplier,both,lead'],
            'country' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'legal_type' => ['nullable', 'string', 'in:company,person'],
            'company_name' => ['required_if:legal_type,company', 'nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required_if:legal_type,person', 'nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'vat_number' => ['nullable', 'string', 'max:30'],
            'fiscal_code' => ['nullable', 'string', 'max:16'],
            'uid_number' => ['nullable', 'string', 'max:20'],
            'sdi_code' => ['nullable', 'string', 'size:7'],
            'pec' => ['nullable', 'email'],
            'document_language' => ['nullable', 'string', 'in:it,en,de,fr'],
            'iban' => ['nullable', 'string', 'max:34'],
            'qr_iban' => ['nullable', 'string', 'max:34'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'address' => ['nullable', 'array'],
            'address.street' => ['required_with:address', 'string'],
            'address.house_number' => ['nullable', 'string'],
            'address.postal_code' => ['required_with:address', 'string'],
            'address.city' => ['required_with:address', 'string'],
            'address.state_canton' => ['nullable', 'string'],
            'address.country' => ['required_with:address', 'string', 'size:2'],
        ];
    }
}
