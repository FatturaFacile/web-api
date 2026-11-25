<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ExpenseManagement\Domain\Enums\CalculationSource;

/**
 * A mileage claim, or a quote of one (the quote needs only the vehicle, the distance and the date).
 */
final class MileageClaimRequest extends FormRequest
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
        $claim = $this->routeIs('*quote') ? 'nullable' : 'required';

        return [
            'expense_date' => [$claim, 'date'],
            'on_date' => ['nullable', 'date'],
            'origin_address' => [$claim, 'string', 'max:300'],
            'destination_address' => [$claim, 'string', 'max:300'],
            'waypoint_addresses' => ['nullable', 'array', 'max:20'],
            'waypoint_addresses.*' => ['string', 'max:300'],
            'distance_km' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'is_round_trip' => ['nullable', 'boolean'],
            'vehicle_id' => ['nullable', 'uuid'],
            'vehicle_class_code' => ['required', 'string', 'max:40'],
            'vehicle_make_model' => ['nullable', 'string', 'max:160'],
            'calculation_source' => ['nullable', Rule::in(CalculationSource::values())],
            'annual_km_bracket' => ['nullable', 'integer', 'min:1000', 'max:100000'],
            'description' => ['nullable', 'string', 'max:500'],
            'project_id' => ['nullable', 'uuid'],
            'billable_to_customer' => ['nullable', 'boolean'],
        ];
    }
}
