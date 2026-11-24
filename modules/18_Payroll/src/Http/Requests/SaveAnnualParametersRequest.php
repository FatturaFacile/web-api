<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Payroll\Calculation\Italian\ItalianPayrollParameters;
use Modules\Payroll\Calculation\Swiss\SwissPayrollParameters;

final class SaveAnnualParametersRequest extends FormRequest
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
            'parameters' => ['required', 'array'],
            'parameters.*' => ['numeric'],
            'legal_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Only the keys the engine of the country reads are accepted.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $country = strtoupper((string) $this->route('country'));

            if (! in_array($country, ['CH', 'IT'], true)) {
                $validator->errors()->add('country', 'Paese non valido.');

                return;
            }

            $known = $country === 'CH' ? (new SwissPayrollParameters)->toArray() : (new ItalianPayrollParameters)->toArray();

            foreach (array_keys((array) $this->input('parameters', [])) as $key) {
                if (! array_key_exists((string) $key, $known)) {
                    $validator->errors()->add("parameters.{$key}", 'Parametro sconosciuto.');
                }
            }
        }];
    }
}
