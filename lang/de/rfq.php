<?php

declare(strict_types=1);

return [
    'mail' => [
        'invitation_subject' => 'Offertanfrage :number — :title',
        'invitation_greeting' => 'Guten Tag :supplier,',
        'invitation_intro' => ':buyer lädt Sie ein, ein Angebot für die Offertanfrage :number (:title) abzugeben.',
        'invitation_deadline' => 'Angebotsfrist: :deadline (UTC).',
        'invitation_cta' => 'Angebotsportal öffnen',
        'invitation_outro' => 'Dieser sichere Link ist persönlich für Ihr Unternehmen bestimmt und läuft mit Ende der Angebotsfrist ab.',

        'deadline_extended_subject' => 'Fristverlängerung: Offertanfrage :number — :title',
        'deadline_extended_body' => 'Die Angebotsfrist für die Offertanfrage :number (:title) von :buyer wurde bis :deadline (UTC) verlängert.',

        'award_subject' => 'Zuschlagsmitteilung: Offertanfrage :number — :title',
        'award_greeting' => 'Herzlichen Glückwunsch :supplier,',
        'award_body_full' => ':buyer hat Ihnen den Gesamtzuschlag für die Offertanfrage :number (:title) erteilt.',
        'award_body_partial' => ':buyer hat Ihnen ausgewählte Positionen der Offertanfrage :number (:title) zugeschlagen.',
        'award_po_numbers' => 'Erstellte Bestellung(en): :orders',

        'regret_subject' => 'Ergebnismitteilung: Offertanfrage :number — :title',
        'regret_greeting' => 'Sehr geehrte Damen und Herren von :supplier,',
        'regret_body' => 'Vielen Dank für Ihr Angebot zur Offertanfrage :number (:title) von :buyer. Nach sorgfältiger Prüfung wurde der Auftrag an einen anderen Anbieter vergeben.',

        'cancelled_subject' => 'Stornierungsmitteilung: Offertanfrage :number — :title',
        'cancelled_body' => 'Bitte beachten Sie, dass die Offertanfrage :number (:title) von :buyer storniert wurde.',
        'cancelled_reason' => 'Grund: :reason',
    ],
];
