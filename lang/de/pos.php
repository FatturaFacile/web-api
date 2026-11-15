<?php

declare(strict_types=1);

return [

    'terminal_label' => 'POS-Terminal',
    'terminal_inactive' => 'Das POS-Terminal ist nicht aktiv.',
    'terminal_busy' => 'Das Terminal hat bereits eine offene Sitzung.',
    'session_closed' => 'Die POS-Sitzung ist nicht offen.',
    'cash_tendered_too_low' => 'Das erhaltene Bargeld deckt den Verkaufsbetrag nicht.',
    'mixed_split_invalid' => 'Bei gemischten Zahlungen muss der Barbetrag positiv und kleiner als der Gesamtbetrag sein.',
    'prepaid_card_required' => 'Prepaid-Zahlungen erfordern einen Gutschein oder eine Prepaid-Karte.',
    'already_refunded' => 'Dieser Verkauf wurde bereits erstattet.',
    'already_transmitted' => 'Ein bereits an den Fiskaldrucker übermittelter Verkauf kann hier nicht erstattet werden.',
    'refund_session_closed' => 'Verkäufe einer geschlossenen Sitzung können nicht erstattet werden.',
    'rt_already_transmitted' => 'Dieser Verkauf wurde bereits an den Fiskaldrucker übermittelt.',
    'rt_refunded_sale' => 'Ein erstatteter Verkauf kann nicht übermittelt werden.',
    'rt_simulated_notice' => 'Simulierter Fiskaldrucker: Es wurde nichts an die Agenzia delle Entrate gesendet.',
    'refund_note' => 'Erstattung des Kassenbons :number',
    'earn_note' => 'Punkte für den Kassenbon :number',
    'redeem_note' => 'Bezahlung des Kassenbons :number',

    'drawer_cash_insufficient' => 'Die Kasse enthaelt nicht genug Bargeld fuer diese Buchung.',
    'ticket_payment_excess' => 'Die unbaren Zahlungen uebersteigen den Betrag, oder es wurde Bargeld fuer einen bereits gedeckten Betrag gegeben.',
    'ticket_payment_short' => 'Die Zahlungen decken den Bonbetrag nicht.',
    'return_sale_not_returnable' => 'Nur ein abgeschlossener Verkauf kann Retouren haben.',
    'return_item_unknown' => 'Die Zeile gehoert nicht zu diesem Verkauf oder ist eine Rabattzeile.',
    'return_quantity_exceeds' => 'Die Menge ist null oder groesser als die noch retournierbare Menge.',
    'x_report_open_only' => 'Der X-Bericht wird bei einer offenen Sitzung gelesen: lesen Sie bei einer geschlossenen den Z-Bericht.',
    'z_report_closed_only' => 'Der Z-Bericht wird beim Schliessen der Sitzung erstellt: lesen Sie bei einer offenen den X-Bericht.',

    'messages' => [
        'terminal_created' => 'POS-Terminal erfolgreich erstellt',
        'session_opened' => 'POS-Sitzung geöffnet',
        'session_closed' => 'POS-Sitzung geschlossen',
        'session_report' => 'POS-Sitzungsbericht erfolgreich abgerufen',
        'sale_registered' => 'Verkauf erfolgreich erfasst',
        'sale_retrieved' => 'Verkauf erfolgreich abgerufen',
        'sale_refunded' => 'Verkauf erfolgreich erstattet',
        'rt_transmitted' => 'Verkauf an den Fiskaldrucker übermittelt',
        'rt_simulated' => 'Verkauf vom simulierten Fiskaldrucker erfasst: Es wurde nichts an die Agenzia delle Entrate gesendet',
        'rt_not_transmitted' => 'Der Fiskaldrucker hat den Verkauf noch nicht akzeptiert: Der Kassenbon bleibt gespeichert und kann erneut gesendet werden',
    ],

];
