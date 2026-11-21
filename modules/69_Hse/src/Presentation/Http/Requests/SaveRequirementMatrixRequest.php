<?php

declare(strict_types=1);

namespace Modules\Hse\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Hse\Domain\Enums\CertificateType;
use Modules\Hse\Domain\Enums\Jurisdiction;

final class SaveRequirementMatrixRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:160'],
            'jurisdiction' => ['required', Rule::enum(Jurisdiction::class)],
            'job_position_id' => ['nullable', 'uuid', 'required_without_all:work_center_id,construction_site_id'],
            'work_center_id' => ['nullable', 'uuid'],
            'construction_site_id' => ['nullable', 'uuid'],
            'suva_hazard_class' => ['nullable', 'string', 'max:16'],
            'dvr_risk_profile_code' => ['nullable', 'string', 'max:48'],
            'required_training_types' => ['nullable', 'array'],
            'required_training_types.*.type' => ['required', Rule::enum(CertificateType::class)],
            'required_training_types.*.validity_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'required_medical_exam_codes' => ['nullable', 'array'],
            'required_medical_exam_codes.*' => ['string', 'max:48'],
            'required_ppe_product_ids' => ['nullable', 'array'],
            'required_ppe_product_ids.*' => ['uuid'],
            'is_active' => ['nullable', 'boolean'],
            'expected_version' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
