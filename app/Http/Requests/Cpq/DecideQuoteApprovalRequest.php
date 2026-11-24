<?php

declare(strict_types=1);

namespace App\Http\Requests\Cpq;

use Illuminate\Foundation\Http\FormRequest;

class DecideQuoteApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A rejection must say why; an approval takes no input.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rejection_reason' => [$this->routeIs('*approvals.reject') ? 'required' : 'nullable', 'string', 'max:2000'],
        ];
    }
}
