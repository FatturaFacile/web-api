<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\DebtCollection\Models\Notice;

final class PolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', Notice::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'jurisdiction' => ['required', Rule::in(['CH', 'IT'])],
            'currency' => ['nullable', 'string', 'size:3'],
            'customer_id' => ['nullable', 'uuid'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'min_overdue_amount' => ['nullable', 'numeric', 'min:0'],
            'interest_basis' => ['nullable', Rule::in(['actual_365', 'actual_360', 'actual_actual'])],
            'contractual_rate_percent' => ['nullable', 'numeric', 'between:0,100'],
            'levels' => ['required', 'array', 'min:1', 'max:4'],
            'levels.*.level' => ['required', 'integer', 'between:0,3', 'distinct'],
            'levels.*.min_days_overdue' => ['required', 'integer', 'between:-365,3650'],
            'levels.*.wait_days' => ['nullable', 'integer', 'between:0,365'],
            'levels.*.deadline_days' => ['nullable', 'integer', 'between:0,365'],
            'levels.*.fee_amount' => ['nullable', 'numeric', 'min:0'],
            'levels.*.charge_interest' => ['nullable', 'boolean'],
            'levels.*.channels' => ['nullable', 'array'],
            'levels.*.channels.*' => [Rule::in(['email', 'sms', 'whatsapp', 'pec', 'registered_mail'])],
            'levels.*.legal_warning' => ['nullable', 'boolean'],
            'levels.*.is_active' => ['nullable', 'boolean'],
        ];
    }
}
