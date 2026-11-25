<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\ElectronicSignature\Enums\Jurisdiction;
use Modules\ElectronicSignature\Enums\RoutingMode;
use Modules\ElectronicSignature\Enums\SignableType;
use Modules\ElectronicSignature\Enums\SignatureLevel;

/**
 * Create or update a signature template (a reusable workflow that also acts as a compliance rule when it has a threshold).
 */
final class SaveTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('signatures.configure');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'name' => ['required', 'string', 'max:160'],
            'document_type' => ['required', Rule::enum(SignableType::class)],
            'required_level' => ['required', Rule::enum(SignatureLevel::class)],
            'jurisdiction' => ['required', Rule::enum(Jurisdiction::class)],
            'provider_config_id' => ['nullable', 'uuid', Rule::exists('signature_provider_configs', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'routing_mode' => ['required', Rule::enum(RoutingMode::class)],
            'expiration_days' => ['required', 'integer', 'min:1', 'max:90'],
            'reminder_days' => ['nullable', 'array', 'max:5'],
            'reminder_days.*' => ['integer', 'min:1', 'max:60'],
            'min_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'amount_currency' => ['nullable', 'string', 'size:3', Rule::in(['CHF', 'EUR'])],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
