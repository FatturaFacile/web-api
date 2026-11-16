<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests\Recruiting;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\HumanResources\Enums\EmploymentType;
use Modules\HumanResources\Enums\RecruitingLocale;

final class StoreJobOpeningRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'position_id' => [$required, 'uuid', Rule::exists('hr_positions', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'location_site' => [$required, 'string', 'max:150'],
            'employment_type' => [$required, 'string', Rule::in(EmploymentType::values())],
            'openings_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'salary_range_public' => ['sometimes', 'nullable', 'array'],
            'salary_range_public.min' => ['nullable', 'numeric', 'min:0'],
            'salary_range_public.max' => ['nullable', 'numeric', 'min:0'],
            'salary_range_public.currency' => ['nullable', 'string', Rule::in(['EUR', 'CHF'])],
            'published_languages' => [$required, 'array', 'min:1'],
            'published_languages.*' => ['string', 'distinct', Rule::in(RecruitingLocale::values())],
            'translations' => [$required, 'array'],
            'translations.*' => ['array'],
            'translations.*.title' => ['nullable', 'string', 'max:200'],
            'translations.*.description' => ['nullable', 'string', 'max:20000'],
            'translations.*.requirements' => ['nullable', 'string', 'max:20000'],
            'translations.*.benefits' => ['nullable', 'string', 'max:20000'],
            'closes_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $min = $this->input('salary_range_public.min');
                $max = $this->input('salary_range_public.max');

                if (is_numeric($min) && is_numeric($max) && (float) $max < (float) $min) {
                    $validator->errors()->add('salary_range_public.max', 'The maximum salary must be greater than or equal to the minimum salary.');
                }
            },
        ];
    }
}
