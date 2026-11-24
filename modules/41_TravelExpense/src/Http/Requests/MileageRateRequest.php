<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\TravelExpense\Models\TravelMileageRate;
use Modules\TravelExpense\Models\TravelTrip;

/**
 * A row of the mileage rate table: jurisdiction, year, vehicle type and the reimbursement per kilometre.
 */
final class MileageRateRequest extends FormRequest
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
            'vehicle_type' => ['required', Rule::in(TravelMileageRate::VehicleTypes)],
            'currency' => ['required', Rule::in(['EUR', 'CHF'])],
            'rate_per_km' => ['required', 'numeric', 'min:0', 'max:999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
