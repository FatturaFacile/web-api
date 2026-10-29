<?php

declare(strict_types=1);

return [
    'messages' => [
        'imported' => 'Kontoauszug erfolgreich importiert',
        'listed' => 'Kontoauszüge erfolgreich abgerufen',
        'retrieved' => 'Kontoauszug erfolgreich abgerufen',
        'reconciled' => 'Bankabstimmung erfolgreich abgeschlossen',
        'matched' => 'Kontoauszugszeile erfolgreich zugeordnet',
        'unmatched' => 'Zuordnung der Kontoauszugszeile aufgehoben',
        'categorized' => 'Kontoauszugszeile erfolgreich kategorisiert',
        'payment_batch_created' => 'Zahlungslauf erfolgreich erstellt',
        'payment_batch_executed' => 'Zahlungslauf ausgeführt (ISO 20022 XML generiert)',
        'collection_recorded' => 'Zahlungseingang erfolgreich verbucht',
    ],

    'errors' => [
        'duplicate_statement' => 'Dieser Kontoauszug wurde bereits importiert.',
        'line_not_credit' => 'Nur Haben-Umsätze können mit Ausgangsrechnungen abgeglichen werden.',
        'line_already_reconciled' => 'Die Zeile ist bereits abgestimmt.',
        'line_not_reconciled' => 'Die Zeile ist nicht abgestimmt.',
        'invoice_not_payable' => 'Die Rechnung kann diese Zahlung nicht empfangen.',
        'overpayment' => 'Der Betrag übersteigt den Rechnungsbetrag.',
        'document_direction_mismatch' => 'Haben-Zeilen gehören zu Kundenrechnungen, Soll-Zeilen zu Lieferantenrechnungen.',
        'category_not_for_credit' => 'Eingänge können nur als interne Umbuchung kategorisiert werden.',
        'supplier_payment_exceeds_balance' => 'Die Zahlung übersteigt den offenen Rechnungsbetrag.',
        'difference_not_accepted' => 'Der Betrag weicht um :difference vom Saldo ab.',
    ],

    'payment_notes' => [
        'payment' => 'Kontoauszugszahlung',
        'bank_fee' => 'Einbehaltene Bankgebühren',
        'adjustment' => 'Rundungsdifferenz',
    ],

    'problem_titles' => [
        'import' => 'Kontoauszugsimport',
    ],
];
