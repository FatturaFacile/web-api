<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;
use Modules\HumanResources\Models\Payroll\WithholdingTariff;

final class ImportWithholdingTariffsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', WithholdingTariff::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ];
    }
}
