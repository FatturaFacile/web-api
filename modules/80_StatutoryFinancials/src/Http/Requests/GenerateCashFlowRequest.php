<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\StatutoryFinancials\Domain\Enums\CashFlowMethod;

final class GenerateCashFlowRequest extends FormRequest
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
        return ['method' => ['nullable', Rule::in(CashFlowMethod::values())]];
    }
}
