<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Models\Contact;
use App\Models\ContactConsent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContactConsentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $contact = Contact::find($this->route('id'));

        return $contact === null || ($this->user()?->can('update', $contact) ?? true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'consents' => ['required', 'array', 'min:1'],
            'consents.*.purpose' => ['required', 'string', Rule::in(ContactConsent::PURPOSES), 'distinct'],
            'consents.*.granted' => ['required', 'boolean'],
            'consents.*.regime' => ['nullable', 'string', Rule::in(ContactConsent::REGIMES)],
            'consents.*.source' => ['nullable', 'string', 'max:50'],
            'consents.*.notes' => ['nullable', 'string'],
        ];
    }
}
