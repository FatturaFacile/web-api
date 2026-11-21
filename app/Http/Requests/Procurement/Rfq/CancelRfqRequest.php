<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement\Rfq;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates optional cancellation reason for an RFQ.
 */
final class CancelRfqRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
