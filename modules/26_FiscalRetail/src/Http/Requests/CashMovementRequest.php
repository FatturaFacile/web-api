<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\FiscalRetail\Models\RetailCashMovement;

/**
 * A pay-in, pay-out or safe drop of the cash drawer.
 */
final class CashMovementRequest extends FormRequest
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
            'type' => ['required', Rule::in(RetailCashMovement::Types)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'reason' => ['nullable', 'string', 'max:190', 'required_if:type,pay_out'],
        ];
    }
}
