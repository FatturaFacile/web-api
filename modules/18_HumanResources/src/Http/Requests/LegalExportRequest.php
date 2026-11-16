<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Parameters for a legal working-time export: Italy LUL/Zucchetti or Switzerland SECO OLL 1.
 */
final class LegalExportRequest extends FormRequest
{
    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['required', 'in:lul_csv,seco_csv'],
            'jurisdiction' => ['required', 'in:IT,CH'],
        ];
    }
}
