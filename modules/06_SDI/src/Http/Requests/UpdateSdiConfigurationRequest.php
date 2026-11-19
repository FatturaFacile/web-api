<?php

declare(strict_types=1);

namespace Modules\SDI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SDI\Enums\SignatureMode;

class UpdateSdiConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'transmitter_country_code' => ['sometimes', 'string', 'size:2'],
            'transmitter_fiscal_code' => ['sometimes', 'nullable', 'string', 'max:28'],
            'channel_type' => ['sometimes', 'nullable', Rule::in(['pec', 'webservice_sdi', 'intermediary_api'])],
            'default_codice_destinatario' => ['sometimes', 'string', 'regex:/^([A-Z0-9]{7}|[A-Z0-9]{6}|XXXXXXX)$/'],
            'pec_address' => ['sometimes', 'nullable', 'email', 'max:255'],
            'signature_mode' => ['sometimes', Rule::enum(SignatureMode::class)],
            'certificate_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'certificate' => ['sometimes', 'file', 'max:512'],
            'certificate_password' => ['required_with:certificate', 'string', 'max:255'],
            'auto_sign_on_send' => ['sometimes', 'boolean'],
            'legal_archiving_enabled' => ['sometimes', 'boolean'],
            'sandbox_simulator_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
