<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CustomerSatisfaction\Domain\Enums\RecoveryStatus;

final class TransitionCaseRequest extends FormRequest
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
            'status' => ['required', Rule::enum(RecoveryStatus::class)],
            'root_cause_code' => ['nullable', 'string', 'max:60'],
            'resolution_summary' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
