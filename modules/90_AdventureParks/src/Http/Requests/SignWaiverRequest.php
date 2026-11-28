<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Signature of the liability waiver. Public: the unguessable token of the ticket in the URL is the credential.
 */
final class SignWaiverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'signer' => ['required', 'array'],
            'signer.full_name' => ['required', 'string', 'max:150'],
            'signer.fiscal_code' => ['required', 'string', 'max:20'],
            'signer.phone' => ['required', 'string', 'max:40'],
            'signer.email' => ['required', 'email', 'max:190'],
            'signer.birth_date' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'signer.height_cm' => ['required', 'numeric', 'between:40,250'],
            'signer.weight_kg' => ['required', 'numeric', 'between:5,300'],
            'minors' => ['nullable', 'array', 'max:100'],
            'minors.*.full_name' => ['required', 'string', 'max:150'],
            'minors.*.birth_date' => ['required', 'date_format:Y-m-d', 'before:today'],
            'minors.*.height_cm' => ['required', 'numeric', 'between:40,250'],
            'minors.*.weight_kg' => ['required', 'numeric', 'between:5,300'],
            'signature_data' => ['required', 'string', 'regex:/^data:image\/(png|jpeg);base64,[A-Za-z0-9+\/=]+$/', 'max:'.(int) config('adventure_parks.signature_max_length', 700000)],
            'accepted_rules' => ['required', 'accepted'],
            'locale' => ['nullable', 'string', Rule::in((array) config('adventure_parks.waiver_locales'))],
        ];
    }
}
