<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class OpenBankingSyncRequest extends FormRequest
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
            'from' => ['nullable', 'date', 'before_or_equal:today'],
            'to' => ['nullable', 'date', 'after_or_equal:from', 'before_or_equal:today'],
            'auto_reconcile' => ['sometimes', 'boolean'],
        ];
    }
}
