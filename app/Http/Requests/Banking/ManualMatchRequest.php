<?php

declare(strict_types=1);

namespace App\Http\Requests\Banking;

use App\Models\Invoice;
use App\Models\PassiveInvoice;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Manual match of a statement line with a document: a customer invoice for an incoming line, a received supplier
 * invoice for an outgoing one (`invoice_id` accepts both). `difference_handling` says how to treat an amount that
 * differs from the balance: `none` (only differences within the tolerance), `write_off` or `partial`.
 */
class ManualMatchRequest extends FormRequest
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
            'invoice_id' => [
                'required', 'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! Invoice::query()->whereKey($value)->exists() && ! PassiveInvoice::query()->whereKey($value)->exists()) {
                        $fail(__('validation.exists', ['attribute' => $attribute]));
                    }
                },
            ],
            'difference_handling' => ['sometimes', Rule::in(['none', 'write_off', 'partial'])],
        ];
    }
}
