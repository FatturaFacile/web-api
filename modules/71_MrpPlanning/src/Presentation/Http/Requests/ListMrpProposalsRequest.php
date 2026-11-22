<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MrpPlanning\Domain\Enums\ExceptionCode;
use Modules\MrpPlanning\Domain\Enums\ProposalStatus;
use Modules\MrpPlanning\Domain\Enums\ProposalType;

/**
 * Filters, sorting and paging of the proposals workbench.
 */
final class ListMrpProposalsRequest extends FormRequest
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
            'run_id' => ['nullable', 'uuid'],
            'proposal_type' => ['nullable', Rule::enum(ProposalType::class)],
            'status' => ['nullable', Rule::enum(ProposalStatus::class)],
            'exception_code' => ['nullable', Rule::enum(ExceptionCode::class)],
            'item_id' => ['nullable', 'uuid'],
            'work_center_id' => ['nullable', 'uuid'],
            'need_by_from' => ['nullable', 'date'],
            'need_by_to' => ['nullable', 'date', 'after_or_equal:need_by_from'],
            'sort_by' => ['nullable', 'string', 'max:40'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
