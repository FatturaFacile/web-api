<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Http\Requests\Concerns\ValidatesContactFiscalData;
use App\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;

class UpdateContactRequest extends FormRequest
{
    use ValidatesContactFiscalData;

    /**
     * The ContactPolicy update ability needs the record, which the controller resolves (in the active tenant) and
     * authorizes; asked here with the class it ended in a TypeError, an HTTP 500 on every update.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeFiscalInput();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = Contact::find($this->route('id') ?? $this->route('contact'));

        return [
            ...$this->fiscalRules((string) ($this->input('country') ?? $current->country ?? 'IT'), $current),
            'type' => ['sometimes', 'string', 'in:client,customer,supplier,both,lead'],
            'country' => ['sometimes', 'string', 'regex:/^[A-Z]{2}$/'],
            'legal_type' => ['nullable', 'string', 'in:company,person'],
            'company_name' => ['required_if:legal_type,company', 'nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required_if:legal_type,person', 'nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:50'],
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
