<?php

declare(strict_types=1);

return [
    'mail' => [
        'magic_link' => [
            'subject' => 'Ihr Anmeldelink für :company',
            'greeting' => 'Guten Tag :name',
            'intro' => 'Öffnen Sie mit der Schaltfläche unten Ihren Kundenbereich bei :company. Der Link funktioniert nur einmal und läuft in :minutes Minuten ab.',
            'action' => 'Meinen Bereich öffnen',
            'security' => 'Wenn Sie diesen Link nicht angefordert haben, können Sie diese E-Mail ignorieren.',
        ],
        'otp' => [
            'subject' => 'Ihr Bestätigungscode für :company',
            'greeting' => 'Guten Tag :name',
            'intro' => 'Geben Sie diesen Code ein, um die Anmeldung bei :company abzuschliessen. Er läuft in :minutes Minuten ab.',
            'security' => 'Geben Sie diesen Code niemals weiter. Wenn Sie sich nicht anmelden wollten, ignorieren Sie diese E-Mail.',
        ],
    ],
    'messages' => [
        'link_requested' => 'Gehört die Adresse zu einem Kunden, wurde ein Anmeldelink gesendet.',
        'otp_required' => 'Ein Bestätigungscode wurde an Ihre E-Mail-Adresse gesendet.',
        'authenticated' => 'Erfolgreich angemeldet.',
        'logged_out' => 'Erfolgreich abgemeldet.',
        'checkout_created' => 'Zahlungsseite erstellt.',
        'ticket_created' => 'Ihre Anfrage wurde gesendet.',
    ],
    'errors' => [
        'otp_invalid' => 'Der Bestätigungscode ist nicht korrekt.',
        'otp_locked' => 'Zu viele falsche Codes. Fordern Sie einen neuen Anmeldelink an.',
        'session_required' => 'Melden Sie sich im Kundenbereich an, um fortzufahren.',
        'invoice_not_payable' => 'Für diese Rechnung ist online nichts mehr zu zahlen.',
        'xml_not_available' => 'Für dieses Dokument existiert keine elektronische Rechnungsdatei.',
        'ticket_target_invalid' => 'Das verknüpfte Dokument gehört nicht zu Ihrem Konto.',
    ],
];
