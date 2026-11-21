<?php

declare(strict_types=1);

namespace Modules\Quoting\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConvertQuoteRequest extends FormRequest
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
        return ['down_payment_percent' => ['nullable', 'numeric', 'gt:0', 'max:100']];
    }
}
