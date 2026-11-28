<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SkiOutdoorSchool\Domain\Enums\RetentionMode;
use Modules\SkiOutdoorSchool\Http\Requests\Concerns\SkiSchoolRules;

/**
 * Settings of the school: public booking portal, jurisdiction and default retention.
 */
final class SaveSettingsRequest extends FormRequest
{
    use SkiSchoolRules;

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
            'public_slug' => ['sometimes', 'string', 'min:4', 'max:80', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('ski_school_settings', 'public_slug')->ignore($this->tenantId(), 'tenant_id')],
            'booking_enabled' => ['nullable', 'boolean'],
            'jurisdiction' => ['nullable', Rule::in(['IT', 'CH'])],
            'default_retention_mode' => ['nullable', Rule::in(RetentionMode::values())],
            'default_retention_value' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'meeting_point' => ['nullable', 'string', 'max:160'],
        ];
    }
}
