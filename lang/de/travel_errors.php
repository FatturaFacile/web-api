<?php

declare(strict_types=1);

return [
    'trip' => [
        'not_editable' => 'Die Reise kann im aktuellen Status nicht mehr geändert werden.',
        'only_draft_editable' => 'Nur eine Reise im Entwurf kann geändert werden: ziehe sie zuerst zurück oder öffne sie erneut.',
        'only_draft_deletable' => 'Nur eine Reise im Entwurf kann gelöscht werden.',
        'submit_only_draft' => 'Nur eine Reise im Entwurf kann eingereicht werden.',
        'overlaps_another' => 'Die Mitarbeiterin oder der Mitarbeiter hat in diesem Zeitraum bereits eine eingereichte, genehmigte oder abgeschlossene Reise.',
        'invalid_transition' => 'Eine Reise im Status :from kann nicht auf :to wechseln.',
        'not_started' => 'Die Reise hat noch nicht begonnen.',
        'reconcile_only_completed' => 'Nur eine abgeschlossene Reise kann abgerechnet werden.',
        'pending_expenses' => ':count verknüpfte Spesen warten noch auf Genehmigung: genehmige oder trenne sie zuerst.',
        'own_only' => 'Du kannst Reisen nur für dich selbst beantragen.',
        'return_before_departure' => 'Die Rückkehrzeit muss nach der Abreisezeit liegen.',
    ],
    'advance' => [
        'exceeds_budget' => 'Der Vorschuss darf das geschätzte Budget nicht übersteigen.',
        'not_payable_now' => 'Der Vorschuss kann nur für eine genehmigte oder abgeschlossene Reise erfasst werden.',
        'none' => 'Die Reise hat keinen Vorschuss.',
        'already_paid' => 'Der Vorschuss wurde bereits als bezahlt erfasst.',
    ],
    'rate' => [
        'per_diem_exists' => 'Für diese Rechtsordnung, dieses Jahr, diesen Bereich und dieses Land gibt es bereits einen Tagessatz.',
        'mileage_exists' => 'Für diese Rechtsordnung, dieses Jahr und diesen Fahrzeugtyp gibt es bereits einen Kilometersatz.',
    ],
    'per_diem' => [
        'day_outside_trip' => 'Ein Tag liegt ausserhalb der Reisedaten.',
        'rate_missing' => 'Kein Tagessatz für :jurisdiction im Jahr :year: füge ihn der Ansatztabelle hinzu.',
        'currency_mismatch' => 'Der Tagessatz ist in :rate, die Reise aber in :trip.',
    ],
    'mileage' => [
        'date_outside_trip' => 'Die Fahrt liegt ausserhalb der Reisedaten.',
        'rate_missing' => 'Kein Kilometersatz für :jurisdiction im Jahr :year für dieses Fahrzeug: füge ihn der Ansatztabelle hinzu.',
        'currency_mismatch' => 'Der Kilometersatz ist in :rate, die Reise aber in :trip.',
    ],
    'leg' => [
        'currency_mismatch' => 'Die Kosten einer Teilstrecke müssen in der Währung der Reise (:trip) angegeben werden.',
        'hotel_needs_dates' => 'Ein Hotelaufenthalt braucht Check-in und Check-out.',
    ],
    'expense' => [
        'not_found' => 'Die Spesen existieren nicht.',
        'other_employee' => 'Die Spesen gehören einer anderen Person.',
        'not_reimbursable' => 'Nur rückerstattbare Spesen können mit einer Reise verknüpft werden.',
        'rejected' => 'Abgelehnte Spesen können nicht mit einer Reise verknüpft werden.',
        'currency_mismatch' => 'Die Spesen sind in :expense, die Reise aber in :trip.',
        'date_outside_trip' => 'Die Spesen sind zu weit von der Reise entfernt datiert.',
        'already_linked' => 'Die Spesen sind bereits mit einer Reise verknüpft.',
    ],
];
