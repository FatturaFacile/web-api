<?php

declare(strict_types=1);

return [
    'order_locked' => 'Nur Entwürfe oder versendete Bestellungen können bearbeitet werden.',
    'order_not_confirmable' => 'Nur Entwürfe von Bestellungen können bestätigt werden.',
    'order_not_receivable' => 'Waren können nur für versendete oder teilgelieferte Bestellungen empfangen werden.',
    'receipt_exceeds_order' => 'Die Menge für ":description" übersteigt die offene Bestellmenge.',
    'nothing_to_receive' => 'Keine offenen Positionen mehr zum Empfang vorhanden.',

    'invalid_fatturapa_title' => 'Ungültige FatturaPA-Datei',
    'unknown_recipient_title' => 'Unbekannter Empfänger',
    'invalid_fatturapa' => 'Die Datei ist kein gültiges FatturaPA-Dokument.',
    'recipient_mismatch' => 'Die Rechnung ist an eine abweichende USt-IdNr. adressiert.',
    'unknown_recipient' => 'Kein Mandant stimmt mit dem Rechnungsempfänger überein.',
    'credit_note_not_payable' => 'Gutschriften können nicht direkt bezahlt werden.',
    'already_paid' => 'Diese Eingangsrechnung ist bereits vollständig beglichen.',

    'messages' => [
        'order_created' => 'Bestellung erfolgreich erstellt',
        'order_updated' => 'Bestellung erfolgreich aktualisiert',
        'order_retrieved' => 'Bestellung erfolgreich abgerufen',
        'order_confirmed' => 'Bestellung bestätigt',
        'goods_received' => 'Wareneingang erfolgreich gebucht',
        'receipts' => 'Wareneingänge erfolgreich abgerufen',
        'receipt' => 'Wareneingang erfolgreich abgerufen',
        'supplier_invoices' => 'Eingangsrechnungen erfolgreich abgerufen',
        'supplier_invoice' => 'Eingangsrechnung erfolgreich abgerufen',
        'invoices_received' => 'Eingangsrechnungen empfangen',
        'marked_paid' => 'Eingangsrechnung als bezahlt markiert',
        'webhook_received' => 'Eingehende Rechnung verarbeitet',
        'three_way_match_passed' => 'Dreiseitiger Rechnungsabgleich erfolgreich',
        'three_way_match_discrepant' => 'Abweichung beim dreiseitigen Rechnungsabgleich festgestellt',
    ],
];
