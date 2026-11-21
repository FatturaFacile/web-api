<?php

declare(strict_types=1);

return [
    'mail' => [
        'invitation_subject' => 'Richiesta di Offerta :number — :title',
        'invitation_greeting' => 'Gentile :supplier,',
        'invitation_intro' => ':buyer vi ha invitato a presentare un\'offerta per la Richiesta di Offerta (RDO) :number (:title).',
        'invitation_deadline' => 'Scadenza presentazione offerte: :deadline (UTC).',
        'invitation_cta' => 'Apri Portale Offerte',
        'invitation_outro' => 'Questo collegamento sicuro è personale per la vostra azienda e scade automaticamente al termine della gara.',

        'deadline_extended_subject' => 'Proroga Scadenza: RDO :number — :title',
        'deadline_extended_body' => 'La scadenza per la RDO :number (:title) emessa da :buyer è stata prorogata al :deadline (UTC).',

        'award_subject' => 'Notifica di Aggiudicazione: RDO :number — :title',
        'award_greeting' => 'Congratulazioni :supplier,',
        'award_body_full' => ':buyer vi ha aggiudicato l\'intera fornitura relativa alla RDO :number (:title).',
        'award_body_partial' => ':buyer vi ha aggiudicato alcune posizioni della RDO :number (:title).',
        'award_po_numbers' => 'Ordini d\'acquisto generati: :orders',

        'regret_subject' => 'Esito Gara: RDO :number — :title',
        'regret_greeting' => 'Gentile :supplier,',
        'regret_body' => 'Vi ringraziamo per aver partecipato alla RDO :number (:title) emessa da :buyer. Dopo un\'attenta valutazione, la fornitura è stata assegnata a un altro fornitore. Confidiamo in future collaborazioni.',

        'cancelled_subject' => 'Avviso di Annullamento: RDO :number — :title',
        'cancelled_body' => 'Vi informiamo che la Richiesta di Offerta :number (:title) emessa da :buyer è stata annullata.',
        'cancelled_reason' => 'Motivazione: :reason',
    ],
];
