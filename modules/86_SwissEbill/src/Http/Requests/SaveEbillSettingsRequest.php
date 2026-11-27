<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SwissEbill\Domain\Enums\EbillProtocol;
use Modules\SwissEbill\Domain\Enums\EbillProvider;
use Modules\SwissEbill\Domain\Support\BillerIdValidator;
use Modules\SwissEbill\Models\EbillSetting;

final class SaveEbillSettingsRequest extends FormRequest
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
        $enabled = $this->boolean('ebill_enabled');
        $real = $this->input('provider') !== EbillProvider::SandboxSimulator->value;

        return [
            'ebill_enabled' => ['sometimes', 'boolean'],
            'biller_id' => [Rule::requiredIf($enabled), 'nullable', 'string', 'max:30', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && $value !== '' && ! BillerIdValidator::isValid((string) $value)) {
                    $fail('invalid_biller_id');
                }
            }],
            'provider' => ['sometimes', Rule::enum(EbillProvider::class)],
            'channel_protocol' => ['sometimes', Rule::enum(EbillProtocol::class)],
            'api_base_url' => [Rule::requiredIf($enabled && $real), 'nullable', 'url:https', 'max:255'],
            'client_id' => ['nullable', 'string', 'max:190'],
            'client_secret' => ['nullable', 'string', 'max:2000'],
            'signing_certificate_pem' => ['nullable', 'string', 'max:20000', 'regex:/-----BEGIN CERTIFICATE-----/'],
            'private_key_pem' => ['nullable', 'string', 'max:20000', 'regex:/-----BEGIN (RSA |EC |ENCRYPTED )?PRIVATE KEY-----/'],
            'webhook_secret' => ['nullable', 'string', 'min:16', 'max:200'],
            'webhook_auth_mode' => ['sometimes', Rule::in(EbillSetting::WEBHOOK_AUTH_MODES)],
            'webhook_mtls_fingerprint' => ['nullable', 'string', 'max:128', 'regex:/^[0-9A-Fa-f:\s]+$/'],
            'auto_send_on_invoice_issue' => ['sometimes', 'boolean'],
            'attach_courtesy_pdf' => ['sometimes', 'boolean'],
            'default_ebinterface_version' => ['sometimes', Rule::in(['ebInterface 6.1', 'SIX eBill XML'])],
        ];
    }
}
