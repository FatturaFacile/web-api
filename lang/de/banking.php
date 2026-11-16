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
        'ledger_account_not_configured' => 'Konfigurieren Sie das Sachkonto :setting in den Bankeinstellungen (banking.ledger_defaults), bevor diese Bewegung gebucht wird.',
        'ledger_account_unknown' => 'Das Sachkonto :code ist nicht im Kontenplan.',
        'bank_account_not_linked' => 'Das Bankkonto :iban ist mit keinem Sachkonto des Kontenplans verknüpft.',
        'posting_period_unavailable' => 'Die Bewegung kann nicht gebucht werden: :reason',
    ],

    'payment_notes' => [
        'payment' => 'Kontoauszugszahlung',
        'bank_fee' => 'Einbehaltene Bankgebühren',
        'adjustment' => 'Rundungsdifferenz',
    ],

    'problem_titles' => [
        'import' => 'Kontoauszugsimport',
    ],

    'parse_errors' => [
        'unsupported_format' => 'Die Datei ist kein unterstützter Kontoauszug (CBI, CAMT.053 oder CAMT.054).',
        'invalid_xml' => 'Die Datei ist kein gültiges XML-Dokument.',
        'unsafe_xml' => 'XML-Dokumente mit DOCTYPE oder Entitätsdeklarationen werden nicht akzeptiert.',
        'no_statement' => 'Das Dokument enthält weder einen Kontoauszug noch eine Avisierung.',
        'invalid_line_length' => 'Zeile :line hat :length Zeichen statt :expected.',
        'invalid_record' => 'Zeile :line enthält einen ungültigen oder unerwarteten Datensatz :record.',
        'missing_header' => 'Der Kontoauszug hat keinen gültigen Kopfsatz (IBAN des Kontos).',
        'missing_trailer' => 'Der Kontoauszug ist unvollständig: Der Abschlusssatz EF fehlt.',
        'record_count_mismatch' => 'Der Abschlusssatz deklariert :expected Datensätze, gelesen wurden jedoch :actual.',
        'empty_file' => 'Die Datei ist leer.',
    ],

];
