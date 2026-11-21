<?php

declare(strict_types=1);

namespace Modules\Hse\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Hse\Domain\Enums\Jurisdiction;
use Modules\Hse\Domain\Enums\TrafficLight;

final class ComplianceMatrixRequest extends FormRequest
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
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'department_id' => ['nullable', 'uuid'],
            'work_center_id' => ['nullable', 'uuid'],
            'construction_site_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::enum(TrafficLight::class)],
            'jurisdiction' => ['nullable', Rule::enum(Jurisdiction::class)],
            'as_of' => ['nullable', 'date'],
        ];
    }
}
