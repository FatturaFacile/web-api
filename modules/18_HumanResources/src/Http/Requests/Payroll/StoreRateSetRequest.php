<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;
use Modules\HumanResources\DTOs\Payroll\RateSet;
use Modules\HumanResources\Models\Payroll\PayrollRateSet;

/**
 * A tenant rate set overrides the system default for its validity window (e.g. the tenant's own pension plan
 * or accident insurer premiums). The `rates` document is fully validated by the RateSet DTO.
 */
final class StoreRateSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PayrollRateSet::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:150'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'rates' => ['required', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $rates = $this->input('rates');

                if (! is_array($rates) || $validator->errors()->has('rates')) {
                    return;
                }

                try {
                    RateSet::fromArray($rates);
                } catch (InvalidArgumentException $exception) {
                    $validator->errors()->add('rates', $exception->getMessage());
                }
            },
        ];
    }
}
