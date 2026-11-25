<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ExpenseManagement\Domain\Enums\PayoutChannel;

final class ExportPayoutRequest extends FormRequest
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
            'channel' => ['required', Rule::enum(PayoutChannel::class)],
            'execution_date' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
