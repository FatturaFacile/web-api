<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Enums\CertificateKind;
use Modules\Associations\Enums\CourseKind;
use Modules\Associations\Models\Course;
use Modules\Associations\Support\TenantRules;

final class SaveCourseRequest extends FormRequest
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
        return [
            'kind' => ['sometimes', Rule::enum(CourseKind::class)],
            'code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'year_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_years')],
            'discipline_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_disciplines')],
            'department_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_departments')],
            'resource_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_resources')],
            'access_area_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_access_areas')],
            'cost_center_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('cost_centers')],
            'fee_plan_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_fee_plans')],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'waitlist_enabled' => ['sometimes', 'boolean'],
            'auto_close_when_full' => ['sometimes', 'boolean'],
            'registrations_open' => ['sometimes', 'boolean'],
            'required_certificate' => ['sometimes', 'nullable', Rule::enum(CertificateKind::class)],
            'status' => ['sometimes', Rule::in([Course::Active, Course::Cancelled, Course::Ended])],
        ];
    }
}
