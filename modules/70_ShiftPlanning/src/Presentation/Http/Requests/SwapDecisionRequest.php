<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The answer of a colleague or of the manager to a swap request. The manager gives a reason when rejecting.
 */
final class SwapDecisionRequest extends FormRequest
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
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'rejection_reason' => ['nullable', 'string', 'max:1000', 'required_if:decision,reject'],
        ];
    }
}
