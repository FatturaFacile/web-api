<?php

declare(strict_types=1);

return [
    'order_already_invoiced' => 'Dieser Auftrag wurde bereits fakturiert.',
    'order_cancelled' => 'Ein stornierter Auftrag kann nicht fakturiert werden.',
    'deposit_line' => 'Anzahlung für Auftrag :number (:percentage%)',
    'deposit_deduction' => 'Abzüglich: Anzahlungsrechnung :number',
    'deposit_exceeds_order' => 'Der Gesamtbetrag der Anzahlungsrechnungen darf den Auftragswert nicht übersteigen.',
    'delivery_note_already_invoiced' => 'Dieser Lieferschein wurde bereits fakturiert.',

    'quote_not_convertible' => 'Ein abgelehnter oder abgelaufener Kostenvoranschlag kann nicht umgewandelt werden.',
    'quote_already_invoiced' => 'Dieser Kostenvoranschlag wurde bereits fakturiert.',
    'quote_already_signed' => 'Dieser Kostenvoranschlag wurde bereits unterzeichnet.',
    'quote_not_signable' => 'Ein abgelehnter oder abgelaufener Kostenvoranschlag kann nicht unterzeichnet werden.',

    'quote_already_converted' => 'Dieser Kostenvoranschlag wurde bereits in einen Auftrag umgewandelt.',
    'order_not_deliverable' => 'Nur bestätigte Aufträge können geliefert werden.',
    'nothing_to_deliver' => 'Keine verbleibenden Positionen zur Lieferung.',
    'proforma_only' => 'Nur Proforma-Rechnungen können umgewandelt werden.',
    'proforma_already_converted' => 'Diese Proforma wurde bereits umgewandelt.',
    'payment_on_proforma' => 'Zahlungen können nicht auf einer Proforma-Rechnung verbucht werden.',
    'payment_exceeds_balance' => 'Die Zahlung übersteigt den fälligen Betrag.',
    'import_line_matched' => 'Diese Zeile wurde bereits zugeordnet.',

    'signature_invalid' => 'Die Unterschrift muss ein PNG-Bild von maximal :max KB sein.',
    'reminder_not_payable' => 'Nur unbezahlte, fällige Rechnungen können gemahnt werden.',
    'reminder_no_email' => 'Der Kontakt verfügt über keine E-Mail-Adresse.',
    'bank_import_note' => 'Bankimport: :detail',

    'messages' => [
        'quotes_list' => 'Kostenvoranschläge erfolgreich abgerufen',
        'quote_created' => 'Kostenvoranschlag erfolgreich erstellt',
        'quote_retrieved' => 'Kostenvoranschlag erfolgreich abgerufen',
        'quote_updated' => 'Kostenvoranschlag erfolgreich aktualisiert',
        'quote_deleted' => 'Kostenvoranschlag erfolgreich gelöscht',
        'quote_to_order' => 'Kostenvoranschlag in Auftrag umgewandelt',
        'quote_submitted' => 'Kostenvoranschlag zur Genehmigung eingereicht',
        'order_created' => 'Verkaufsauftrag erfolgreich erstellt',
        'order_confirmed' => 'Auftrag erfolgreich bestätigt',
        'order_hold_placed' => 'Kreditsperre für Auftrag aktiviert',
        'order_hold_released' => 'Kreditsperre erfolgreich aufgehoben',
        'delivery_note_created' => 'Lieferschein erfolgreich erstellt',
        'goods_dispatched' => 'Waren erfolgreich versendet',
        'invoice_created' => 'Rechnung erfolgreich erstellt',
        'invoice_posted' => 'Rechnung erfolgreich gebucht',
        'credit_note_created' => 'Gutschrift erfolgreich ausgestellt',
        'credit_applied' => 'Gutschrift erfolgreich verrechnet',
    ],
];
