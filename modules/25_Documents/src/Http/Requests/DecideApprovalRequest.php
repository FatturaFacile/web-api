<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the decision of an approver. A rejection must say why; an approval may carry a comment.
 */
final class DecideApprovalRequest extends FormRequest
{
    /**
     * Access is decided by module.access and the approver check of the service.
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
        return ['comment' => [$this->routeIs('dms.approval-requests.reject') ? 'required' : 'nullable', 'string', 'max:1000']];
    }
}
