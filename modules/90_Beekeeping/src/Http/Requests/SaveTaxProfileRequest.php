<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Requests;

use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Beekeeping\Domain\Enums\Country;
use Modules\Beekeeping\Domain\Enums\TaxRegime;
use Modules\Beekeeping\Models\TaxProfile;

final class SaveTaxProfileRequest extends BeekeepingRequest
{
    public function authorize(): bool
    {
        return Gate::allows('configureTax', TaxProfile::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'country' => ['required', Rule::enum(Country::class)],
            'regime' => ['required', Rule::enum(TaxRegime::class)],
            'annual_turnover' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'compensation_overrides' => ['nullable', 'array'],
            'compensation_overrides.*' => ['numeric', 'between:0,30'],
        ];
    }
}
