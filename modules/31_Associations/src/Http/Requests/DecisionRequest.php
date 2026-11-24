<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approval or rejection, with the reason of a rejection.
 */
final class DecisionRequest extends FormRequest
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
            'approved' => ['required', 'boolean'],
            'reason' => ['required_if:approved,false', 'nullable', 'string', 'max:500'],
        ];
    }
}
