<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\TravelExpense\Models\TravelTrip;

/**
 * The jurisdiction and year whose reference rates are added to the rate tables of the company.
 */
final class SeedRatesRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policy.
     */
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
            'jurisdiction' => ['required', Rule::in(TravelTrip::Jurisdictions)],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ];
    }
}
