<?php

declare(strict_types=1);

namespace Modules\DownPayments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\DownPayments\Domain\Enums\DepositResolution;

/**
 * The way out of a held deposit: imputation to the price, forfeiture on breach or refund (single or double).
 */
final class ResolveDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is enforced by the policy in the controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'resolution' => ['required', Rule::enum(DepositResolution::class)],
            'date' => ['nullable', 'date'],
            'refund_mode' => ['nullable', 'string', 'in:single,double', 'required_if:resolution,refund'],
            'payment_account' => ['nullable', 'string', 'in:bank,cash'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
