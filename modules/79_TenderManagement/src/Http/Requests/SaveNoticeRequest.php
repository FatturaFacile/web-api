<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\TenderManagement\Domain\Enums\AwardCriterion;
use Modules\TenderManagement\Domain\Enums\FormulaType;
use Modules\TenderManagement\Domain\Enums\Jurisdiction;
use Modules\TenderManagement\Domain\Enums\NoticeStatus;
use Modules\TenderManagement\Domain\Enums\ProcedureType;
use Modules\TenderManagement\Domain\Enums\VaultDocType;
use Modules\TenderManagement\Domain\Support\SoaClassification;

/**
 * Creation and edit of a tender notice. The CIG and CUP formats are those of the ANAC and the CIPE registers.
 */
final class SaveNoticeRequest extends FormRequest
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
        $partial = $this->isMethod('PUT') ? 'sometimes' : 'required';
        $tenantId = (string) (app(TenantContext::class)->getTenantId() ?? '');

        return [
            'title' => [$partial, 'string', 'max:240'],
            'description' => ['nullable', 'string', 'max:10000'],
            'jurisdiction' => [$partial, Rule::in(Jurisdiction::values())],
            'procedure_type' => ['sometimes', Rule::in(ProcedureType::values())],
            'contracting_authority_name' => [$partial, 'string', 'max:200'],
            'contracting_authority_contact_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'simap_project_number' => ['nullable', 'string', 'max:60'],
            'cig_code_it' => ['nullable', 'string', 'regex:/^[A-Z0-9]{10}$/'],
            'cup_code_it' => ['nullable', 'string', 'regex:/^[A-Z0-9]{15}$/'],
            'rup_name' => ['nullable', 'string', 'max:160'],
            'dedicated_bank_account_it' => ['nullable', 'string', 'max:34', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{1,30}$/'],
            'cpv_category_codes' => ['nullable', 'array', 'max:50'],
            'cpv_category_codes.*' => ['string', 'regex:/^\d{8}(-\d)?$/'],
            'award_criterion' => ['sometimes', Rule::in(AwardCriterion::values())],
            'formula_type' => ['nullable', Rule::in(FormulaType::values())],
            'currency' => ['sometimes', 'string', 'size:3'],
            'base_auction_amount' => ['sometimes', 'numeric', 'min:0'],
            'non_subject_safety_costs_amount' => ['sometimes', 'numeric', 'min:0'],
            'estimated_labor_cost_amount' => ['sometimes', 'numeric', 'min:0'],
            'technical_max_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'economic_max_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'required_soa_categories' => ['nullable', 'array', 'max:40'],
            'required_soa_categories.*.category' => ['required_with:required_soa_categories', 'string', Rule::in(SoaClassification::categories())],
            'required_soa_categories.*.classification' => ['required_with:required_soa_categories', 'string', Rule::in(SoaClassification::Order)],
            'required_min_turnover' => ['nullable', 'numeric', 'min:0'],
            'turnover_reference_years' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'required_similar_projects_count' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'required_similar_projects_amount' => ['nullable', 'numeric', 'min:0'],
            'required_certifications' => ['nullable', 'array', 'max:30'],
            'required_certifications.*' => ['string', Rule::in(VaultDocType::values())],
            'publication_date' => ['nullable', 'date'],
            'clarification_deadline_at' => ['nullable', 'date'],
            'submission_peremptory_deadline_at' => array_filter(['nullable', 'date', $this->filled('clarification_deadline_at') ? 'after_or_equal:clarification_deadline_at' : null]),
            'status' => ['sometimes', Rule::in(NoticeStatus::values())],
        ];
    }
}
