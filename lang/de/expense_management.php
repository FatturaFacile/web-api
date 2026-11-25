<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Spesendaten geladen.',
        'saved' => 'Gespeichert.',
        'deleted' => 'Gelöscht.',
        'submitted' => 'Die Spesenabrechnung wurde zur Genehmigung eingereicht.',
        'approved' => 'Die Spesenabrechnung wurde genehmigt.',
        'rejected' => 'Die Spesenabrechnung wurde abgelehnt.',
        'exported' => 'Die Auszahlung wurde erfasst.',
        'scanned' => 'Beleg gelesen.',
    ],
    'errors' => [
        'report_not_editable' => 'Die Spesenabrechnung kann nicht mehr bearbeitet werden.',
        'report_empty' => 'Die Spesenabrechnung enthält weder Positionen noch Pauschalen.',
        'blocking_violations' => ':count Position(en) mit blockierenden Richtlinienverstössen.',
        'missing_receipt' => 'Ein Beleg ist erforderlich.',
        'invalid_transition' => 'Die Spesenabrechnung kann nicht von :from zu :to wechseln.',
        'self_approval_forbidden' => 'Sie können Ihre eigene Spesenabrechnung nicht genehmigen.',
        'rejection_reason_required' => 'Zum Ablehnen ist eine Begründung erforderlich.',
        'no_reimbursable_amount' => 'Es gibt nichts zu erstatten.',
        'employee_iban_missing' => 'Der Mitarbeitende hat keine IBAN im Lohnprofil.',
        'debtor_account_missing' => 'Kein aktives Firmenkonto in der Währung der Abrechnung.',
        'card_transaction_mismatch' => 'Der Kartenumsatz passt nicht zur Spesenposition (Währung oder Daten).',
        'card_transaction_already_matched' => 'Der Kartenumsatz oder die Spesenposition ist bereits zugeordnet.',
        'amount_exceeds_transaction' => 'Die zugeordneten Beträge übersteigen den Kartenumsatz (:amount).',
        'vehicle_rate_not_found' => 'Kein Kilometeransatz für Fahrzeug :vehicle an diesem Datum.',
        'currency_rate_missing' => 'Kein Wechselkurs von :from nach :to; bitte Kurs erfassen.',
        'receipt_not_found' => 'Beleg nicht gefunden.',
        'period_invalid' => 'Das Periodenende liegt vor dem Beginn.',
        'category_not_found' => 'Spesenkategorie nicht gefunden.',
        'approved_amount_exceeds' => 'Der genehmigte Betrag übersteigt den erstattungsfähigen Betrag (:max).',
    ],
];
