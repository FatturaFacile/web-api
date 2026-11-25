<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ScanReceiptRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf,txt', 'max:'.(int) config('expense_management.receipts.max_kilobytes', 10240)],
            'ocr_text' => ['nullable', 'string', 'max:20000'],
            'expense_report_id' => ['nullable', 'uuid'],
        ];
    }
}
