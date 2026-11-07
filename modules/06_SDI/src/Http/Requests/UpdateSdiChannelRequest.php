<?php

declare(strict_types=1);

namespace Modules\SDI\Http\Requests;

use App\Rules\PublicHost;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SDI\Enums\SdiChannel;

class UpdateSdiChannelRequest extends FormRequest
{
    /**
     * Mail ports of certified mail providers; the hosts must be public, so the settings cannot probe internal services.
     *
     * @var list<int>
     */
    public const array SMTP_PORTS = [465, 587, 25];

    /** @var list<int> */
    public const array IMAP_PORTS = [993, 143];

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
            'pec_smtp_host' => ['required_if:channel,pec', 'nullable', 'string', 'max:255', new PublicHost],
            'pec_smtp_port' => ['nullable', 'integer', Rule::in(self::SMTP_PORTS)],
            'pec_imap_host' => ['required_if:channel,pec', 'nullable', 'string', 'max:255', new PublicHost],
            'pec_imap_port' => ['nullable', 'integer', Rule::in(self::IMAP_PORTS)],
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
