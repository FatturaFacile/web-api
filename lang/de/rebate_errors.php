<?php

declare(strict_types=1);

return [
    'agreement' => [
        'not_active' => 'Die Vereinbarung ist nicht aktiv.',
        'locked' => 'Bei einer Vereinbarung, die kein Entwurf mehr ist, können nur Name, Notizen und Enddatum geändert werden.',
        'period' => 'Das Enddatum darf nicht vor dem Startdatum liegen.',
        'not_draft' => 'Nur ein Entwurf kann aktiviert werden.',
        'tiers_required' => 'Fügen Sie vor der Aktivierung mindestens eine Staffel hinzu.',
        'delete_draft_only' => 'Nur ein Entwurf ohne Abgrenzungen kann gelöscht werden.',
        'code_taken' => 'Der Code :code wird bereits von einer anderen Vereinbarung verwendet.',
        'tiers_distinct' => 'Jede Staffel braucht eine andere Schwelle.',
    ],
    'accrual' => [
        'date_outside' => 'Das Datum muss im Vereinbarungszeitraum liegen (:from bis :to).',
    ],
    'settlement' => [
        'nothing_to_settle' => 'Es gibt keinen aufgelaufenen Rabatt zur Abrechnung.',
        'tax_rate_required' => 'Geben Sie den MwSt-Satz der Gutschrift an.',
        'credit_note_note' => 'Mengenrabatt :name (:code)',
        'credit_note_line' => 'Mengenrabatt :name, Zeitraum :from bis :to',
    ],
    'promotion' => [
        'percentage_max' => 'Ein prozentualer Rabatt darf 100 nicht überschreiten.',
        'period' => 'Das Enddatum darf nicht vor dem Startdatum liegen.',
        'code_taken' => 'Der Code :code wird bereits von einer anderen Aktion verwendet.',
    ],
];
