<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Wms\Models\WmsDockAppointment;

/**
 * Payload to create or change a dock appointment.
 */
final class DockAppointmentRequest extends WmsFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'carrier_name' => ['required', 'string', 'max:160'],
            'reference' => ['nullable', 'string', 'max:100'],
            'direction' => ['required', Rule::in(WmsDockAppointment::Directions)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'cross_dock_order_id' => ['nullable', 'uuid', $this->ownRow('wms_cross_dock_orders')],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->isMethod('POST') ? [
            'dock_door_id' => ['required', 'uuid', $this->ownRow('wms_dock_doors')],
            ...$rules,
        ] : $rules;
    }

    /**
     * Cross-field rules.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $start = $this->valueOf('starts_at', 'wms_dock_appointments');
            $end = $this->valueOf('ends_at', 'wms_dock_appointments');

            if ($start !== null && $end !== null && Carbon::parse((string) $end)->lessThanOrEqualTo(Carbon::parse((string) $start))) {
                $validator->errors()->add('ends_at', __('wms_errors.dock.ends_before_start'));
            }
        });
    }
}
