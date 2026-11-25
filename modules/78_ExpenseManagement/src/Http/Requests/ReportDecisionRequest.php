<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approval (optional amount and comment) or rejection (reason required) of a report.
 */
final class ReportDecisionRequest extends FormRequest
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
            'approved_amount' => ['nullable', 'numeric', 'min:0'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'reason' => [$this->routeIs('*reject') ? 'required' : 'nullable', 'string', 'max:1000'],
        ];
    }
}
