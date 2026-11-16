<?php

declare(strict_types=1);

return [
    'trip' => [
        'not_editable' => 'La trasferta non può più essere modificata nello stato attuale.',
        'only_draft_editable' => 'Solo una trasferta in bozza può essere modificata: ritirala o riaprila prima.',
        'only_draft_deletable' => 'Solo una trasferta in bozza può essere eliminata.',
        'submit_only_draft' => 'Solo una trasferta in bozza può essere inviata.',
        'overlaps_another' => 'Il dipendente ha già una trasferta inviata, approvata o completata in queste date.',
        'invalid_transition' => 'Una trasferta in stato :from non può passare a :to.',
        'not_started' => 'La trasferta non è ancora iniziata.',
        'reconcile_only_completed' => 'Solo una trasferta completata può essere riconciliata.',
        'pending_expenses' => ':count nota/e spese collegata/e è/sono ancora in attesa di approvazione: approvala/e o scollegala/e prima.',
        'own_only' => 'Puoi richiedere trasferte solo per te stesso.',
        'return_before_departure' => 'L\'ora di rientro deve essere successiva all\'ora di partenza.',
    ],
    'advance' => [
        'exceeds_budget' => 'L\'anticipo non può superare il budget stimato.',
        'not_payable_now' => 'L\'anticipo può essere registrato solo per una trasferta approvata o completata.',
        'none' => 'La trasferta non ha un anticipo.',
        'already_paid' => 'L\'anticipo è già stato registrato come pagato.',
    ],
    'rate' => [
        'per_diem_exists' => 'Esiste già una diaria per questa giurisdizione, anno, ambito e paese.',
        'mileage_exists' => 'Esiste già un rimborso chilometrico per questa giurisdizione, anno e tipo di veicolo.',
    ],
    'per_diem' => [
        'day_outside_trip' => 'Un giorno è fuori dalle date della trasferta.',
        'rate_missing' => 'Nessuna diaria per :jurisdiction nel :year: aggiungila alla tabella delle tariffe.',
        'currency_mismatch' => 'La diaria è in :rate ma la trasferta è in :trip.',
    ],
    'mileage' => [
        'date_outside_trip' => 'Il viaggio è fuori dalle date della trasferta.',
        'rate_missing' => 'Nessun rimborso chilometrico per :jurisdiction nel :year per questo veicolo: aggiungilo alla tabella delle tariffe.',
        'currency_mismatch' => 'Il rimborso chilometrico è in :rate ma la trasferta è in :trip.',
    ],
    'leg' => [
        'currency_mismatch' => 'Il costo di una tratta deve essere nella valuta della trasferta (:trip).',
        'hotel_needs_dates' => 'Un soggiorno in hotel richiede check-in e check-out.',
    ],
    'expense' => [
        'not_found' => 'La nota spese non esiste.',
        'other_employee' => 'La nota spese appartiene a un altro dipendente.',
        'not_reimbursable' => 'Solo una nota spese rimborsabile può essere collegata a una trasferta.',
        'rejected' => 'Una nota spese rifiutata non può essere collegata a una trasferta.',
        'currency_mismatch' => 'La nota spese è in :expense ma la trasferta è in :trip.',
        'date_outside_trip' => 'La nota spese è datata troppo lontano dalla trasferta.',
        'already_linked' => 'La nota spese è già collegata a una trasferta.',
    ],
];
