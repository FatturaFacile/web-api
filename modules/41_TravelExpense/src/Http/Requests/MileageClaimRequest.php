<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\TravelExpense\Models\TravelMileageRate;

/**
 * A journey with the own vehicle, reimbursed by kilometre.
 */
final class MileageClaimRequest extends FormRequest
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
            'travel_date' => ['required', 'date_format:Y-m-d'],
            'from_location' => ['required', 'string', 'max:150'],
            'to_location' => ['required', 'string', 'max:150'],
            'vehicle_type' => ['required', Rule::in(TravelMileageRate::VehicleTypes)],
            'round_trip' => ['nullable', 'boolean'],
            'distance_km' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
