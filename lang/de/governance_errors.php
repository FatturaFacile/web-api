<?php

declare(strict_types=1);

return [
    'status' => [
        'unknown' => 'Unbekannter Status :status.',
        'not_allowed' => 'Der Status kann nicht von :from auf :to wechseln: erlaubt :allowed.',
    ],
    'risk' => [
        'closed' => 'Das Risiko ist geschlossen: Öffnen Sie es wieder, um es zu ändern.',
        'owner_required' => 'Ein Risiko braucht vor der Bewertung einen Verantwortlichen.',
        'active_control_required' => 'Ein Risiko wird nur behandelt, wenn eine aktive Kontrolle es mindert: Verknüpfen Sie zuerst eine Kontrolle.',
        'acceptance_notes_required' => 'Die Akzeptanz eines Risikos braucht die Begründung in den Notizen.',
        'residual_exceeds_inherent' => 'Restwahrscheinlichkeit und Restauswirkung dürfen die inhärenten Werte nicht übersteigen.',
        'open_findings' => 'Eine offene Feststellung bezieht sich auf dieses Risiko: Schliessen oder übertragen Sie die Feststellung zuerst.',
    ],
    'control' => [
        'test_needs_active' => 'Die Kontrolle ist :status: nur eine aktive Kontrolle wird getestet.',
        'finding_title' => 'Kontrolltest nicht bestanden: :code :title',
    ],
    'plan' => [
        'closed' => 'Der Auditplan ist geschlossen.',
        'year_locked' => 'Das Jahr eines Plans mit Audits kann nicht geändert werden.',
        'audit_required' => 'Ein Plan braucht vor der Genehmigung mindestens ein Audit.',
        'audits_unfinished' => 'Jedes Audit muss geschlossen oder abgebrochen sein, bevor der Plan geschlossen wird: :codes.',
    ],
    'audit' => [
        'locked' => 'Das Audit ist :status und kann nicht geändert werden.',
        'only_team_and_conclusion' => 'Das Audit ist :status: nur Team und Schlussfolgerung können geändert werden.',
        'dates_inverted' => 'Das geplante Ende darf nicht vor dem geplanten Beginn liegen.',
        'plan_not_approved' => 'Der Auditplan ist :status: er muss vor dem Start eines Audits genehmigt sein.',
        'conclusion_required' => 'Ein Audit wird mit einer Schlussfolgerung abgeschlossen.',
        'untreated_findings' => 'Hohe und kritische Feststellungen noch offen: :codes.',
        'cancellation_reason_required' => 'Das Abbrechen eines Audits braucht den Grund in den Notizen.',
        'outside_plan_year' => 'Das Audit muss im Jahr des Plans beginnen (:year).',
    ],
    'finding' => [
        'audit_not_in_fieldwork' => 'Das Audit ist :status: Feststellungen werden erfasst, solange es läuft oder im Bericht ist.',
        'locked' => 'Die Feststellung ist :status und kann nicht geändert werden.',
        'action_required' => 'Erfassen Sie zuerst mindestens eine Korrekturmassnahme.',
        'acceptance_reason_required' => 'Die Risikoakzeptanz einer Feststellung braucht den Grund in den Notizen.',
        'closure_notes_required' => 'Das Schliessen einer Feststellung braucht die Abschlussnotizen.',
        'live_actions' => 'Brechen Sie die Korrekturmassnahmen ab oder schliessen Sie sie ab, bevor Sie die Feststellung wieder öffnen.',
        'actions_unverified' => 'Jede Korrekturmassnahme muss verifiziert oder abgebrochen sein (mindestens eine verifiziert): :codes.',
    ],
    'action' => [
        'finding_closed' => 'Die Feststellung ist :status: es können keine neuen Massnahmen erfasst werden.',
        'locked' => 'Die Massnahme ist :status und kann nicht geändert werden.',
        'segregation_of_duties' => 'Die Massnahme kann nicht von der Person verifiziert werden, die sie abgeschlossen hat.',
        'rejection_notes_required' => 'Das Zurückgeben einer Massnahme braucht den Grund in den Notizen.',
        'cancellation_notes_required' => 'Das Abbrechen einer Massnahme braucht den Grund in den Notizen.',
        'term_too_long' => 'Das Fälligkeitsdatum ist für eine Feststellung :severity zu weit entfernt: die Frist beträgt :days Tage (bis :limit).',
    ],
    'obligation' => [
        'locked' => 'Die Pflicht ist :status und kann nicht geändert werden.',
        'not_open' => 'Die Pflicht ist :status: nur eine ausstehende oder laufende Pflicht wird abgeschlossen.',
    ],
    'sod_document' => [
        'conflict' => 'Aufgabentrennung: Wer :document vorbereitet hat, kann ihn nicht auch genehmigen oder freigeben.',
        'documents' => [
            'payment' => 'eine Zahlung',
            'purchase_order' => 'eine Bestellung',
            'journal' => 'eine Buchung',
            'payroll_run' => 'einen Lohnlauf',
        ],
    ],
];
