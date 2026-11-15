<?php

declare(strict_types=1);

return [
    'carrier' => [
        'in_use' => 'The carrier still has open transport orders or trips.',
    ],
    'tariff' => [
        'valid_to_before_from' => 'The tariff table cannot end before it starts.',
        'range_order' => 'The upper bound of a range must be higher than its lower bound.',
        'band_overlap' => 'The band overlaps another band of this tariff table for the same zones (weight from :from to :to).',
    ],
    'rate' => [
        'no_rate' => 'No active tariff of :carrier covers this shipment.',
    ],
    'order' => [
        'window_order' => 'A window cannot end before it starts.',
        'not_editable' => 'A transport order that is :status can no longer be changed.',
        'not_deletable' => 'A transport order that is :status cannot be deleted: cancel it instead.',
        'invalid_transition' => 'The transport order is :status: this step is not possible.',
        'carrier_inactive' => 'The carrier :carrier is not active.',
        'trip_carrier' => 'The order is on a trip of another carrier.',
        'on_trip' => 'The order is on a trip: take it off the trip first.',
    ],
    'trip' => [
        'ends_before_start' => 'The trip cannot end before it starts.',
        'not_editable' => 'A trip that is :status can no longer be changed.',
        'capacity_below_load' => 'The capacity cannot go below the load already consolidated (:load).',
        'not_deletable' => 'Only a planned trip without orders can be deleted.',
        'order_not_found' => 'One of the orders does not exist in this company.',
        'order_not_planned' => 'The order :number is :status: only planned orders can be consolidated.',
        'order_on_trip' => 'The order :number is already on a trip.',
        'order_other_carrier' => 'The order :number belongs to another carrier than the trip.',
        'over_weight' => 'The load of :total kg exceeds the capacity of :capacity kg.',
        'over_volume' => 'The load of :total cbm exceeds the capacity of :capacity cbm.',
        'order_not_on_trip' => 'The order is not on this trip.',
        'stops_mismatch' => 'The list must contain every stop of the trip exactly once.',
        'delivery_before_pickup' => 'A delivery cannot come before the pickup of the same order.',
        'stop_not_found' => 'The stop does not belong to this trip.',
        'stop_done' => 'The stop is already completed.',
        'stop_out_of_sequence' => 'Earlier stops are still pending: complete them first.',
        'departure_before_arrival' => 'The departure cannot be before the arrival.',
        'no_stops' => 'A trip without stops cannot be dispatched.',
        'stops_pending' => ':count stops of the trip are still pending.',
        'invalid_transition' => 'The trip is :status: this step is not possible.',
    ],
    'tracking' => [
        'order_closed' => 'The order :number is :status: it takes no tracking events.',
    ],
    'quote' => [
        'no_offers' => 'No active carrier has a tariff for this shipment.',
        'not_open' => 'The quote is already :status.',
        'not_offered' => 'This carrier made no offer in the quote.',
        'awarded_locked' => 'An awarded quote cannot be deleted.',
    ],
    'invoice' => [
        'disputed' => 'The invoice is under dispute or already resolved: it cannot be changed or deleted.',
        'resolved' => 'The dispute is resolved: the invoice cannot be audited again.',
        'nothing_to_dispute' => 'Audit the invoice first: only an over billed, under billed or unmatched invoice can be disputed.',
        'dispute_exists' => 'The invoice already has a dispute (:status).',
        'no_open_dispute' => 'The invoice has no open dispute.',
        'credit_range' => 'The credited amount cannot exceed :limit.',
        'credit_only_when_credited' => 'A credited amount only goes with the credited resolution.',
        'order_other_carrier' => 'The transport order does not belong to the carrier of the invoice.',
    ],
];
