<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Models\Contact;
use App\Models\ContactPerson;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and update of a role-classified contact person (POST: required fields enforced; PUT/PATCH: partial).
 */
class ContactPersonRequest extends FormRequest
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
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => [$presence, 'string', 'max:100'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', Rule::in(ContactPerson::ROLES)],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', 'string', 'max:5'],
            'is_primary' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
