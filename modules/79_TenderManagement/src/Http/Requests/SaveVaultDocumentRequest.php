<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\TenderManagement\Domain\Enums\VaultDocType;
use Modules\TenderManagement\Domain\Enums\VerificationStatus;
use Modules\TenderManagement\Domain\Support\SoaClassification;

/**
 * Creation, edit and renewal of a qualification vault document (JSON or multipart with the scanned file).
 */
final class SaveVaultDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $soa = $this->input('soa_categories_classifications');

        if (is_string($soa)) {
            $this->merge(['soa_categories_classifications' => json_decode($soa, true) ?: null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST') && $this->route('id') === null;

        return [
            'doc_type' => [$creating ? 'required' : 'sometimes', Rule::in(VaultDocType::values())],
            'title' => ['nullable', 'string', 'max:200'],
            'document_number' => ['nullable', 'string', 'max:80'],
            'issuing_body' => ['nullable', 'string', 'max:160'],
            'soa_categories_classifications' => ['nullable', 'array', 'max:40'],
            'soa_categories_classifications.*.category' => ['required_with:soa_categories_classifications', 'string', Rule::in(SoaClassification::categories())],
            'soa_categories_classifications.*.classification' => ['required_with:soa_categories_classifications', 'string', Rule::in(SoaClassification::Order)],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'intermediate_triennial_check_date' => ['nullable', 'date'],
            'numeric_value' => ['nullable', 'numeric', 'min:0'],
            'verification_status' => ['sometimes', Rule::in([VerificationStatus::PendingRenewal->value, VerificationStatus::Valid->value])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'file' => ['nullable', 'file', 'max:'.(int) config('tender_management.uploads.max_kilobytes', 20480)],
        ];
    }
}
