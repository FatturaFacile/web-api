<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\HumanResources\Services\EmployeeDocumentService;

/**
 * An upload to the personnel file (PDF, image or office document, at most 20 MB) with its retention date. A payslip or
 * a contract cannot get a retention date before the end of its statutory period.
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
            'expiry_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', Rule::when($this->filled('issue_date'), 'after_or_equal:issue_date')],
            'retention_until' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:today'],
            'contains_health_data' => ['sometimes', 'boolean'],
            'is_confidential' => ['sometimes', 'boolean'],
            'visible_to_employee' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['document_type', 'issue_date', 'retention_until']) || ! is_string($this->input('retention_until'))) {
                return;
            }

            $issueDate = is_string($this->input('issue_date')) ? CarbonImmutable::parse($this->input('issue_date')) : null;
            $statutoryEnd = EmployeeDocumentService::statutoryRetentionEnd((string) $this->input('document_type'), $issueDate, CarbonImmutable::now());

            if ($statutoryEnd !== null && CarbonImmutable::parse($this->input('retention_until'))->isBefore($statutoryEnd)) {
                $validator->errors()->add('retention_until', "Questo documento è soggetto a conservazione obbligatoria: la data di conservazione non può essere precedente al {$statutoryEnd->format('d.m.Y')}.");
            }
        }];
    }
}
