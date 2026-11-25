<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\FranchiseNetwork\Http\Requests\Concerns\ScopesToTenant;

final class SimulateFormulaRequest extends FormRequest
{
    use ScopesToTenant;

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
            'net_sales' => ['required', 'numeric', 'min:0'],
            'opened_on' => ['nullable', 'date'],
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ];
    }
}
