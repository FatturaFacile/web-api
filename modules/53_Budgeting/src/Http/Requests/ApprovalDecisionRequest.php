<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Requests;

/**
 * The comment of a step of the approval of a version (required to reject).
 */
final class ApprovalDecisionRequest extends BudgetingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['comment' => ['nullable', 'string', 'max:1000']];
    }
}
