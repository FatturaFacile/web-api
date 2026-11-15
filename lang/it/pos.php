<?php

declare(strict_types=1);

return [

    'terminal_label' => 'Terminale POS',
    'terminal_inactive' => 'Il terminale POS non è attivo.',
    'terminal_busy' => 'Il terminale ha già una sessione aperta.',
    'session_closed' => 'La sessione POS non è aperta.',
    'cash_tendered_too_low' => 'Il contante ricevuto non copre il totale della vendita.',
    'mixed_split_invalid' => "Nei pagamenti misti l'importo in contanti deve essere positivo e inferiore al totale.",
    'prepaid_card_required' => 'I pagamenti prepagati richiedono un buono o una carta prepagata.',
    'already_refunded' => 'Questa vendita è già stata rimborsata.',
    'already_transmitted' => 'Una vendita già trasmessa al registratore telematico non può essere rimborsata qui.',
    'refund_session_closed' => 'Le vendite di una sessione chiusa non possono essere rimborsate.',
    'rt_already_transmitted' => 'Questa vendita è già stata trasmessa al registratore telematico.',
    'rt_refunded_sale' => 'Una vendita rimborsata non può essere trasmessa.',
    'rt_simulated_notice' => "Registratore telematico simulato: nulla è stato inviato all'Agenzia delle Entrate.",
    'refund_note' => 'Rimborso dello scontrino :number',
    'earn_note' => 'Punti per lo scontrino :number',
    'redeem_note' => 'Pagamento dello scontrino :number',

    'drawer_cash_insufficient' => 'Nel cassetto non c’e abbastanza contante per questo movimento.',
    'ticket_payment_excess' => 'I pagamenti non in contanti superano l’importo dovuto, oppure e stato dato contante per un importo gia coperto.',
    'ticket_payment_short' => 'I pagamenti non coprono il totale dello scontrino.',
    'return_sale_not_returnable' => 'Solo una vendita completata puo avere resi.',
    'return_item_unknown' => 'La riga non appartiene a questa vendita o e una riga di sconto.',
    'return_quantity_exceeds' => 'La quantita e zero o supera quella ancora restituibile.',
    'x_report_open_only' => 'Il rapporto X si legge su una sessione aperta: per una chiusa leggi il rapporto Z.',
    'z_report_closed_only' => 'Il rapporto Z viene emesso alla chiusura della sessione: per una aperta leggi il rapporto X.',

    'messages' => [
        'terminal_created' => 'Terminale POS creato con successo',
        'session_opened' => 'Sessione POS aperta',
        'session_closed' => 'Sessione POS chiusa',
        'session_report' => 'Rapporto della sessione POS recuperato con successo',
        'sale_registered' => 'Vendita registrata con successo',
        'sale_retrieved' => 'Vendita recuperata con successo',
        'sale_refunded' => 'Vendita rimborsata con successo',
        'rt_transmitted' => 'Vendita trasmessa al registratore telematico',
        'rt_simulated' => "Vendita registrata dal registratore telematico simulato: nulla è stato inviato all'Agenzia delle Entrate",
        'rt_not_transmitted' => 'Il registratore telematico non ha ancora accettato la vendita: lo scontrino è conservato e può essere reinviato',
    ],

];
