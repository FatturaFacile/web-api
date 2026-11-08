<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An upload to the personnel file (PDF, image or office document, at most 20 MB) with its retention date.
 */
final class StoreEmployeeDocumentRequest extends FormRequest
{
    /**
     * Module access is checked by the route middleware.
     */
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
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,doc,docx,odt,txt'],
            'document_type' => ['required', Rule::in(['contract', 'amendment', 'id_card', 'work_permit', 'medical_fitness', 'certificate', 'training', 'nda', 'warning', 'payslip', 'other'])],
            'title' => ['required', 'string', 'max:200'],
            'issue_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'expiry_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'retention_until' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:today'],
            'contains_health_data' => ['sometimes', 'boolean'],
            'is_confidential' => ['sometimes', 'boolean'],
            'visible_to_employee' => ['sometimes', 'boolean'],
        ];
    }
}
