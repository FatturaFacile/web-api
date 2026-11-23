<?php

declare(strict_types=1);

return [
    'agreement' => [
        'bonus_required' => 'Geben Sie das Ziel und den Betrag des Fixbonus ein.',
        'contributions_required' => 'Fügen Sie mindestens einen Beitrag hinzu, bevor Sie die Vereinbarung aktivieren.',
        'version_conflict' => 'Die Vereinbarung wurde von jemand anderem geändert. Laden Sie sie neu und versuchen Sie es erneut.',
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
        'purchase_only' => 'Abrechnungsvorschläge sind nur für Lieferantenvereinbarungen (Einkauf) verfügbar.',
        'invoice_required' => 'Wählen Sie die Lieferantenrechnung, mit der die Rückvergütung verrechnet wird.',
        'invoice_not_found' => 'Die Rechnung existiert nicht oder gehört nicht zum Lieferanten der Vereinbarung.',
        'insufficient_open_balance' => 'Der offene Saldo der Rechnung (:open) deckt die Rückvergütung nicht.',
        'invalid_transition' => 'Dieser Schritt ist für einen Vorschlag im Status :status nicht erlaubt.',
        'version_conflict' => 'Der Vorschlag wurde von jemand anderem geändert. Laden Sie ihn neu und versuchen Sie es erneut.',
        'vat_account_required' => 'Wählen Sie das MwSt-Konto für die Buchung der MwSt der Rückvergütung.',
        'charge_note' => 'Lieferantenrückvergütung :name (:code)',
        'charge_line' => 'Lieferantenrückvergütung :name, Zeitraum :from bis :to',
        'set_off_description' => 'Rückvergütung :code mit Rechnung :invoice verrechnet',
        'nothing_to_settle' => 'Es gibt keinen aufgelaufenen Rabatt zur Abrechnung.',
        'tax_rate_required' => 'Geben Sie den MwSt-Satz der Gutschrift an.',
        'credit_note_note' => 'Mengenrabatt :name (:code)',
        'credit_note_line' => 'Mengenrabatt :name, Zeitraum :from bis :to',
    ],
    'contribution' => [
        'agreement_closed' => 'Die Vereinbarung ist abgeschlossen: Es können keine Beiträge mehr hinzugefügt werden.',
        'locked' => 'Nur ein geplanter Beitrag kann geändert oder entfernt werden.',
    ],
    'run' => [
        'purchase_only' => 'Abgrenzungsläufe sind nur für Lieferantenvereinbarungen (Einkauf) verfügbar.',
        'period_locked' => 'Die Periode ist gesperrt: Ein Abgrenzungslauf deckt sie bereits bis zum :date ab. Stornieren Sie zuerst diesen Lauf.',
        'not_posted' => 'Nur ein gebuchter Abgrenzungslauf kann storniert werden.',
        'not_latest' => 'Nur der letzte Abgrenzungslauf der Vereinbarung kann storniert werden.',
        'already_settled' => 'Die Abgrenzungen dieses Laufs sind bereits Teil eines Abrechnungsvorschlags.',
        'journal_description' => 'Abgrenzung Lieferantenrückvergütung :code bis :to',
    ],
    'promotion' => [
        'percentage_max' => 'Ein prozentualer Rabatt darf 100 nicht überschreiten.',
        'period' => 'Das Enddatum darf nicht vor dem Startdatum liegen.',
        'code_taken' => 'Der Code :code wird bereits von einer anderen Aktion verwendet.',
    ],
];
