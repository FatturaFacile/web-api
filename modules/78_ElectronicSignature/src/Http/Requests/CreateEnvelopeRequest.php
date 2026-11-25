<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Http\Requests;

use App\Models\Translation;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\ElectronicSignature\Enums\AuthMethod;
use Modules\ElectronicSignature\Enums\Jurisdiction;
use Modules\ElectronicSignature\Enums\RecipientRole;
use Modules\ElectronicSignature\Enums\RoutingMode;
use Modules\ElectronicSignature\Enums\SignableType;
use Modules\ElectronicSignature\Enums\SignatureLevel;

/**
 * Create a signature envelope for a business document or an uploaded file, optionally sending it at once.
 */
final class CreateEnvelopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('signatures.send');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $type = (string) $this->input('signable_type');

        return [
            'signable_type' => ['required', Rule::enum(SignableType::class)],
            'signable_id' => [$type === SignableType::Upload->value ? 'nullable' : 'required', 'uuid'],
            'file' => [$type === SignableType::Upload->value ? 'required' : 'nullable', 'file', 'max:15360'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'title' => ['nullable', 'string', 'max:200'],
            'subject' => ['nullable', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:2000'],
            'required_level' => ['required', Rule::enum(SignatureLevel::class)],
            'jurisdiction' => ['required', Rule::enum(Jurisdiction::class)],
            'routing_mode' => ['nullable', Rule::enum(RoutingMode::class)],
            'provider_config_id' => ['nullable', 'uuid', Rule::exists('signature_provider_configs', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'template_id' => ['nullable', 'uuid', Rule::exists('signature_templates', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'expiration_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'reminder_days' => ['nullable', 'array', 'max:5'],
            'reminder_days.*' => ['integer', 'min:1', 'max:60'],
            'locale' => ['nullable', 'string', Rule::in(array_merge(Translation::SUPPORTED_LOCALES, array_keys(Translation::REGIONAL_LOCALES)))],
            'send' => ['nullable', 'boolean'],
            'recipients' => ['required', 'array', 'min:1', 'max:20'],
            'recipients.*.full_name' => ['required', 'string', 'max:160'],
            'recipients.*.email' => ['required', 'email:rfc', 'max:190'],
            'recipients.*.phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'recipients.*.role' => ['nullable', Rule::enum(RecipientRole::class)],
            'recipients.*.signing_order' => ['nullable', 'integer', 'min:1', 'max:50'],
            'recipients.*.auth_method' => ['nullable', Rule::enum(AuthMethod::class)],
            'recipients.*.identity_metadata' => ['nullable', 'array'],
            'recipients.*.identity_metadata.fiscal_code' => ['nullable', 'string', 'max:32'],
            'recipients.*.identity_metadata.ahv_number' => ['nullable', 'string', 'max:32'],
            'recipients.*.identity_metadata.country' => ['nullable', 'string', 'size:2'],
            'recipients.*.signature_box' => ['nullable', 'array'],
            'recipients.*.signature_box.page' => ['required_with:recipients.*.signature_box', 'integer', 'min:1', 'max:1000'],
            'recipients.*.signature_box.x' => ['required_with:recipients.*.signature_box', 'numeric', 'between:0,1'],
            'recipients.*.signature_box.y' => ['required_with:recipients.*.signature_box', 'numeric', 'between:0,1'],
            'recipients.*.signature_box.width' => ['required_with:recipients.*.signature_box', 'numeric', 'between:0.05,1'],
            'recipients.*.signature_box.height' => ['required_with:recipients.*.signature_box', 'numeric', 'between:0.03,1'],
        ];
    }

    /**
     * Multipart bodies carry numbers and booleans as strings; the signature boxes are normalised to numbers.
     */
    protected function prepareForValidation(): void
    {
        $recipients = $this->input('recipients');

        if (! is_array($recipients)) {
            return;
        }

        foreach ($recipients as $index => $recipient) {
            if (is_array($recipient) && is_array($recipient['signature_box'] ?? null)) {
                $recipients[$index]['signature_box'] = array_map(static fn (mixed $value): mixed => is_numeric($value) ? $value + 0 : $value, $recipient['signature_box']);
            }
        }

        $this->merge(['recipients' => $recipients]);

        if ($this->has('send')) {
            $this->merge(['send' => filter_var($this->input('send'), FILTER_VALIDATE_BOOLEAN)]);
        }
    }
}
