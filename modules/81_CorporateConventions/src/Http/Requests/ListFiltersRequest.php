<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CorporateConventions\Domain\Enums\ConventionStatus;
use Modules\CorporateConventions\Domain\Enums\EnrollmentStatus;
use Modules\CorporateConventions\Domain\Enums\PartnerType;
use Modules\CorporateConventions\Domain\Enums\ReimbursementStatus;
use Modules\CorporateConventions\Domain\Enums\SplitBillingStatus;
use Modules\CorporateConventions\Domain\Enums\WelfareJurisdiction;
use Modules\CorporateConventions\Domain\Enums\WelfareProvider;

/**
 * Filters and paging shared by the list endpoints (conventions, enrolments, split billings, welfare vouchers).
 */
final class ListFiltersRequest extends FormRequest
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
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'country_code' => ['sometimes', Rule::in(['IT', 'CH'])],
            'partner_type' => ['sometimes', Rule::in(PartnerType::values())],
            'convention_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::in([...ConventionStatus::values(), ...EnrollmentStatus::values(), ...SplitBillingStatus::values(), ...ReimbursementStatus::values()])],
            'provider' => ['sometimes', Rule::in(WelfareProvider::values())],
            'jurisdiction' => ['sometimes', Rule::in(WelfareJurisdiction::values())],
            'batch' => ['sometimes', 'string', 'max:80'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
