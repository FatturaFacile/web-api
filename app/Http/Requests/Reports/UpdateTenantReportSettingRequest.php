<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use App\Models\NotificationPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTenantReportSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'timezone' => ['required', 'string', 'timezone:all'],
            'default_frequency' => ['required', 'string', Rule::in(NotificationPreference::FREQUENCIES)],
            'default_send_time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'sender_name' => ['nullable', 'string', 'max:160'],
            'sender_email' => ['nullable', 'email:rfc', 'max:190'],
            'logo_url' => ['nullable', 'string', 'max:500'],
            'primary_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'custom_header_html' => ['nullable', 'string', 'max:5000'],
            'custom_footer_html' => ['nullable', 'string', 'max:5000'],
            'enabled_modules' => ['required', 'array', 'min:1'],
            'enabled_modules.*' => ['string', Rule::in(NotificationPreference::MODULES)],
            'rate_limit_per_hour' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
