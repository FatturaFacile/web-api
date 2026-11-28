<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SkiOutdoorSchool\Domain\Enums\CollaborationRegime;
use Modules\SkiOutdoorSchool\Domain\Enums\Discipline;
use Modules\SkiOutdoorSchool\Domain\Enums\LicenseType;
use Modules\SkiOutdoorSchool\Domain\Enums\Proficiency;
use Modules\SkiOutdoorSchool\Domain\Enums\RetentionMode;
use Modules\SkiOutdoorSchool\Http\Requests\Concerns\SkiSchoolRules;

/**
 * Create or change an instructor of the roster.
 */
final class SaveInstructorRequest extends FormRequest
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
            'contact_id' => ['nullable', 'uuid', $this->inTenant('contacts')],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'license_type' => ['required', Rule::in(LicenseType::values())],
            'license_number' => ['nullable', 'string', 'max:60'],
            'license_valid_until' => ['nullable', 'date'],
            'hourly_rate' => ['required', 'numeric', 'min:0', 'max:100000'],
            'retention_mode' => ['nullable', Rule::in(RetentionMode::values())],
            'retention_value' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'collaboration_regime' => ['nullable', Rule::in(CollaborationRegime::values())],
            'withholding_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'max_weekly_hours' => ['nullable', 'numeric', 'min:0', 'max:168'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'languages' => ['required', 'array', 'min:1', 'max:12'],
            'languages.*.code' => ['required', 'string', 'regex:/^[A-Za-z]{2,3}$/'],
            'languages.*.proficiency' => ['nullable', Rule::in(Proficiency::values())],
            'disciplines' => ['required', 'array', 'min:1'],
            'disciplines.*' => [Rule::in(Discipline::values())],
        ];
    }
}
