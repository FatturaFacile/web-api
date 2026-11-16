<?php

declare(strict_types=1);

return [
    'vehicle' => [
        'plate_taken' => 'The license plate :plate is already used by another vehicle.',
        'odometer_decrease' => 'The odometer cannot decrease: the vehicle is already at :current km.',
        'retired' => 'The vehicle is :status: it cannot take new assignments or fuel entries.',
        'open_assignment' => 'The vehicle still has an open driver assignment: end it before selling or disposing of the vehicle.',
        'asset_missing' => 'The asset does not exist in this company.',
    ],
    'odometer' => [
        'below_earlier' => 'The reading of :km km is lower than the reading of :earlier km on :date.',
        'above_later' => 'The reading of :km km is higher than the reading of :later km on :date.',
    ],
    'assignment' => [
        'overlap' => 'The vehicle is already assigned from :from to :to.',
        'driver_required' => 'Choose an employee or enter the name of the external driver.',
        'employee_missing' => 'The employee does not exist in this company.',
        'end_odometer_low' => 'The odometer at return cannot be lower than the odometer at handover.',
        'already_ended' => 'The assignment has already ended.',
    ],
    'fuel' => [
        'unit_electric' => 'An electric vehicle is charged in kWh.',
        'unit_not_electric' => 'The vehicle does not use electricity: record liters or kg.',
        'price_or_total' => 'Enter the unit price or the total cost.',
    ],
    'service' => [
        'schedule_mismatch' => 'The scheduled service belongs to another vehicle.',
    ],
    'schedule' => [
        'due_required' => 'Set an interval, or a due date or due kilometres.',
    ],
    'deadline' => [
        'not_open' => 'The deadline has already been renewed.',
        'renewal_date' => 'The new expiry date must be after :date.',
    ],
];
