<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * When and how money left or reached the association, to post it to the ledger.
 */
final class PaymentDetailsRequest extends FormRequest
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
            'paid_on' => ['required', 'date'],
            'payment_method' => ['required', Rule::in(['cash', 'bank'])],
        ];
    }
}
