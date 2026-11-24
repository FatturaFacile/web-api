<?php

declare(strict_types=1);

return [

    'errors' => [
        'not_dispatchable' => 'Only an unscheduled, scheduled or dispatched work order can be dispatched.',
        'window_invalid' => 'The end of the intervention must be after its start.',
        'technician_unavailable' => 'The technician is not available.',
        'schedule_conflict' => 'The technician already has an overlapping intervention.',
        'skills_missing' => 'The technician lacks the required skills: :skills.',
        'no_van' => 'The technician has no active van warehouse.',
        'van_stock_short' => 'The van warehouse does not hold the spare parts required by this work order.',
        'parts_not_open' => 'Parts can be consumed only on a dispatched work order that is not completed.',
        'report_not_open' => 'A service report can be signed only on a dispatched, en route or on-site work order.',
        'photos_not_in_work_order' => 'One or more photos do not belong to this work order.',
        'report_exists' => 'This work order already has a signed service report.',
        'meter_regression' => 'The meter reading is lower than the one already recorded for this equipment.',
        'report_not_invoiceable' => 'The service report must be signed and the work order completed before invoicing.',
        'report_already_invoiced' => 'This service report has already been invoiced.',
        'nothing_billable' => 'Everything on this service report is covered by the contract or warranty: there is nothing to invoice.',
        'transition_invalid' => 'The work order cannot move from :from to :to.',
    ],

    'pdf' => [
        'title' => 'Service Report',
        'report_number' => 'Report no.',
        'work_order' => 'Work order',
        'customer' => 'Customer',
        'site' => 'Site',
        'equipment' => 'Equipment',
        'technician' => 'Technician',
        'signed_at' => 'Signed on',
        'work_performed' => 'Work performed',
        'checklist' => 'Checklist',
        'result_pass' => 'Pass',
        'result_fail' => 'Fail',
        'result_na' => 'N/A',
        'meter_readings' => 'Meter readings',
        'photos' => 'Photos',
        'operating_hours' => 'Operating hours',
        'cycles' => 'Cycles',
        'charges' => 'Labor, travel and parts',
        'description' => 'Description',
        'quantity' => 'Qty',
        'unit_price' => 'Unit price',
        'amount' => 'Amount',
        'status' => 'Status',
        'covered' => 'Covered by contract/warranty',
        'billable' => 'Billable',
        'kind_labor' => 'Labor (hours)',
        'kind_travel' => 'Travel time (hours)',
        'kind_callout' => 'Callout fee',
        'kind_part' => 'Spare part',
        'travel_km' => 'Travel distance (km)',
        'total_billable' => 'Total billable',
        'total_covered' => 'Total covered',
        'signature' => 'Customer signature',
        'signed_by' => 'Signed by',
        'integrity_hash' => 'Integrity hash (SHA-256)',
    ],

    'mail' => [
        'subject' => 'Service report :number',
        'body' => 'Please find attached the signed service report :number for the intervention carried out at your site.',
    ],

];
