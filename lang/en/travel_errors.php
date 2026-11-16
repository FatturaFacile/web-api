<?php

declare(strict_types=1);

return [
    'trip' => [
        'not_editable' => 'The trip can no longer be changed in its current status.',
        'only_draft_editable' => 'Only a draft trip can be changed: withdraw it or reopen it first.',
        'only_draft_deletable' => 'Only a draft trip can be deleted.',
        'submit_only_draft' => 'Only a draft trip can be submitted.',
        'overlaps_another' => 'The employee already has a submitted, approved or completed trip in these dates.',
        'invalid_transition' => 'A trip that is :from cannot become :to.',
        'not_started' => 'The trip has not started yet.',
        'reconcile_only_completed' => 'Only a completed trip can be reconciled.',
        'pending_expenses' => ':count linked expense(s) are still waiting for approval: approve or unlink them first.',
        'own_only' => 'You can only request trips for yourself.',
        'return_before_departure' => 'The return time must be after the departure time.',
    ],
    'advance' => [
        'exceeds_budget' => 'The advance cannot exceed the estimated budget.',
        'not_payable_now' => 'The advance can be recorded only for an approved or completed trip.',
        'none' => 'The trip has no advance.',
        'already_paid' => 'The advance has already been recorded as paid.',
    ],
    'rate' => [
        'per_diem_exists' => 'A per-diem rate already exists for this jurisdiction, year, scope and country.',
        'mileage_exists' => 'A mileage rate already exists for this jurisdiction, year and vehicle type.',
    ],
    'per_diem' => [
        'day_outside_trip' => 'A day is outside the dates of the trip.',
        'rate_missing' => 'No per-diem rate for :jurisdiction in :year: add it to the rate table.',
        'currency_mismatch' => 'The per-diem rate is in :rate but the trip is in :trip.',
    ],
    'mileage' => [
        'date_outside_trip' => 'The journey is outside the dates of the trip.',
        'rate_missing' => 'No mileage rate for :jurisdiction in :year for this vehicle: add it to the rate table.',
        'currency_mismatch' => 'The mileage rate is in :rate but the trip is in :trip.',
    ],
    'leg' => [
        'currency_mismatch' => 'The cost of a leg must be in the currency of the trip (:trip).',
        'hotel_needs_dates' => 'A hotel stay needs check-in and check-out.',
    ],
    'expense' => [
        'not_found' => 'The expense does not exist.',
        'other_employee' => 'The expense belongs to another employee.',
        'not_reimbursable' => 'Only a reimbursable expense can be linked to a trip.',
        'rejected' => 'A rejected expense cannot be linked to a trip.',
        'currency_mismatch' => 'The expense is in :expense but the trip is in :trip.',
        'date_outside_trip' => 'The expense is dated too far from the trip.',
        'already_linked' => 'The expense is already linked to a trip.',
    ],
];
