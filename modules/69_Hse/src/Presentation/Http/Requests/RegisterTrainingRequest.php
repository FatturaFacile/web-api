<?php

declare(strict_types=1);

namespace Modules\Hse\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Hse\Domain\Enums\CertificateType;

final class RegisterTrainingRequest extends FormRequest
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
            'employee_id' => ['required', 'uuid'],
            'certificate_type' => ['required', Rule::enum(CertificateType::class)],
            'certificate_number' => ['nullable', 'string', 'max:96'],
            'training_provider_name' => ['nullable', 'string', 'max:160'],
            'hours_completed' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'issued_at' => ['required', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'specific_risk_class' => ['nullable', 'boolean'],
            'certificate_pdf_base64' => ['nullable', 'string'],
        ];
    }
}
