<?php

declare(strict_types=1);

namespace Modules\Hse\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Hse\Domain\Enums\ClearanceContext;

final class ClearanceCheckRequest extends FormRequest
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
            'employee_id' => ['required', 'uuid'],
            'target_context_type' => ['required', Rule::enum(ClearanceContext::class)],
            'target_id' => ['required', 'string', 'max:96'],
            'scheduled_date_time' => ['required', 'date'],
        ];
    }
}
