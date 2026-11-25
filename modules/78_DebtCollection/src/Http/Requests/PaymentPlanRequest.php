<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\DebtCollection\Models\Notice;

final class PaymentPlanRequest extends FormRequest
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
            'customer_id' => ['required', 'uuid'],
            'currency' => ['required', 'string', 'size:3'],
            'total_amount' => ['required', 'numeric', 'gt:0'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'receivable_item_ids' => ['nullable', 'array'],
            'receivable_item_ids.*' => ['uuid'],
            'installments' => ['nullable', 'array', 'max:120'],
            'installments.*.due_date' => ['required', 'date_format:Y-m-d'],
            'installments.*.amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
