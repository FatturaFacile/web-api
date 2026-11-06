<?php

declare(strict_types=1);

namespace Modules\SDI\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SDI\Enums\SdiChannel;

class UpdateSdiChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::enum(SdiChannel::class)],
            'environment' => ['sometimes', Rule::in(['production', 'sandbox'])],
            'pec_email' => ['required_if:channel,pec', 'nullable', 'email'],
            'pec_smtp_host' => ['required_if:channel,pec', 'nullable', 'string', 'max:255'],
            'pec_smtp_port' => ['nullable', 'integer', 'between:1,65535'],
            'pec_imap_host' => ['required_if:channel,pec', 'nullable', 'string', 'max:255'],
            'pec_imap_port' => ['nullable', 'integer', 'between:1,65535'],
            'pec_username' => ['required_if:channel,pec', 'nullable', 'string', 'max:255'],
            'pec_password' => ['nullable', 'string', 'max:255'],
            'pec_address' => ['nullable', 'email', 'regex:/^sdi\d+@pec\.fatturapa\.it$/i'],
            'credentials' => ['nullable', 'array'],
            'credentials.token' => ['nullable', 'string'],
            'credentials.email' => ['nullable', 'email'],
            'credentials.password' => ['nullable', 'string'],
            'credentials.api_key' => ['nullable', 'string'],
            'webhook_secret' => ['nullable', 'string', 'min:16', 'max:255'],
            'sign_outgoing' => ['sometimes', 'boolean'],
            'zero_rate_nature' => ['nullable', 'string', 'regex:/^N[1-7](\.\d{1,2})?$/'],
            'ade_telematic_address_registered' => ['sometimes', 'boolean'],
            'ade_conservation_agreement_at' => ['nullable', 'date'],
        ];
    }
}
