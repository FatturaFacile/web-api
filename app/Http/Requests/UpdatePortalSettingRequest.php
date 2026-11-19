<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PortalSetting;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePortalSettingRequest extends FormRequest
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
        $ownId = PortalSetting::query()->where('tenant_id', app(TenantContext::class)->getTenantId())->value('id');

        return [
            'portal_slug' => ['nullable', 'string', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', Rule::unique('portal_settings', 'portal_slug')->ignore($ownId)],
            'custom_domain' => ['nullable', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', Rule::unique('portal_settings', 'custom_domain')->ignore($ownId)],
            'logo_url' => ['nullable', 'url:https', 'max:500'],
            'favicon_url' => ['nullable', 'url:https', 'max:500'],
            'primary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'support_email' => ['nullable', 'email:rfc', 'max:254'],
            'privacy_policy_url' => ['nullable', 'url:https', 'max:500'],
            'require_otp' => ['sometimes', 'boolean'],
            'allow_partial_payments' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['portal_slug', 'custom_domain'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => mb_strtolower(trim($this->input($field)))]);
            }
        }
    }
}
