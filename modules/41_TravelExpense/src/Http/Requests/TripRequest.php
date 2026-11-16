<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\TravelExpense\Models\TravelTrip;

/**
 * The request of a business trip: where, when, why, the estimated budget and the advance asked for.
 */
final class TripRequest extends FormRequest
{
    use TenantScopedRules;

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
            'user_id' => ['nullable', 'uuid', $this->tenantMember()],
            'purpose' => ['required', 'string', 'max:500'],
            'destination_city' => ['required', 'string', 'max:120'],
            'destination_country' => ['required', 'string', 'size:2', 'alpha'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'departure_time' => ['nullable', 'date_format:H:i'],
            'return_time' => ['nullable', 'date_format:H:i'],
            'currency' => ['nullable', Rule::in(['EUR', 'CHF'])],
            'rate_jurisdiction' => ['nullable', Rule::in(TravelTrip::Jurisdictions)],
            'estimated_budget' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'advance_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ];
    }

    /**
     * A one-day trip must return after it left.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $data = $validator->getData();

                if (($data['start_date'] ?? null) !== null && ($data['start_date'] ?? null) === ($data['end_date'] ?? null)
                    && ! empty($data['departure_time']) && ! empty($data['return_time']) && $data['return_time'] <= $data['departure_time']) {
                    $validator->errors()->add('return_time', __('travel_errors.trip.return_before_departure'));
                }
            },
        ];
    }
}
