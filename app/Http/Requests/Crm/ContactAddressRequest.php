<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Models\Contact;
use App\Models\ContactAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        $contact = Contact::find($this->route('id'));

        return $contact === null || ($this->user()?->can('update', $contact) ?? true);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('country'))) {
            $this->merge(['country' => strtoupper(trim($this->input('country')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'type' => [$presence, 'string', Rule::in(ContactAddress::TYPES)],
            'label' => ['nullable', 'string', 'max:100'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'street' => [$presence, 'string', 'max:255'],
            'house_number' => ['nullable', 'string', 'max:20'],
            'postal_code' => [$presence, 'string', 'max:20'],
            'city' => [$presence, 'string', 'max:255'],
            'state_canton' => ['nullable', 'string', 'max:50'],
            'country' => [$presence, 'string', 'size:2'],
            'is_default' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
