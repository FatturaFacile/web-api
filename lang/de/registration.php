<?php

declare(strict_types=1);

return [
    'messages' => [
        'registered' => 'Registrierung erhalten. Bitte prüfen Sie Ihr Postfach, um Ihre E-Mail-Adresse zu bestätigen.',
        'verified' => 'E-Mail-Adresse bestätigt. Ihr Konto wartet nun auf die Freigabe.',
        'resent' => 'Falls die Adresse bestätigt werden muss, erhalten Sie eine neue E-Mail.',
        'requests_listed' => 'Zugriffsanfragen abgerufen',
        'request_decided' => 'Zugriffsanfrage aktualisiert',
    ],
    'errors' => [
        'email_not_verified_title' => 'E-Mail nicht bestätigt',
        'email_not_verified_detail' => 'Bitte bestätigen Sie Ihre E-Mail-Adresse vor der Anmeldung.',
        'approval_pending_title' => 'Konto wartet auf Freigabe',
        'approval_pending_detail' => 'Ein Administrator muss Ihren Zugriff auf das Unternehmen noch freigeben.',
        'access_rejected_title' => 'Zugriff nicht freigegeben',
        'access_rejected_detail' => 'Ihre Zugriffsanfrage wurde nicht freigegeben.',
        'token_invalid_title' => 'Ungültiger Link',
        'token_invalid_detail' => 'Der Bestätigungslink ist ungültig oder wurde bereits verwendet.',
        'token_expired_title' => 'Abgelaufener Link',
        'token_expired_detail' => 'Der Bestätigungslink ist abgelaufen. Bitte fordern Sie einen neuen an.',
        'forbidden_title' => 'Vorgang nicht erlaubt',
        'forbidden_detail' => 'Dieser Vorgang ist Plattform-Administratoren vorbehalten.',
        'not_decidable_title' => 'Anfrage nicht änderbar',
        'not_decidable_detail' => 'Die Anfrage wurde bereits bearbeitet oder der Benutzer hat die E-Mail-Adresse noch nicht bestätigt.',
        'invalid_tax_id' => 'Die MwSt.-Nummer oder UID ist für das gewählte Land ungültig.',
    ],
    'mail' => [
        'greeting' => 'Guten Tag :name,',
        'support' => 'Für Support schreiben Sie an :email.',
        'signature' => 'Ihr :app-Team',
        'verify' => [
            'subject' => 'Bestätigen Sie Ihre E-Mail-Adresse - :app',
            'intro' => 'Vielen Dank für Ihre Registrierung bei :app. Bitte bestätigen Sie Ihre E-Mail-Adresse, um fortzufahren.',
            'action' => 'E-Mail-Adresse bestätigen',
            'expires' => 'Dieser Link ist gültig bis :date.',
            'approval' => 'Nach der Bestätigung gibt ein Administrator Ihren Zugriff auf das Unternehmen frei und Sie erhalten eine E-Mail.',
            'security' => 'Wenn Sie diese Registrierung nicht angefordert haben, ignorieren Sie diese Nachricht: Es wird kein Konto aktiviert.',
        ],
        'exists' => [
            'subject' => 'Registrierungsversuch für Ihr Konto - :app',
            'intro' => 'Jemand hat versucht, sich mit dieser E-Mail-Adresse bei :app zu registrieren. Sie gehört bereits zu einem Konto.',
            'security' => 'Falls Sie es waren, melden Sie sich mit Ihren Zugangsdaten an. Andernfalls können Sie diese Nachricht ignorieren: Ihr Konto wurde nicht geändert.',
        ],
        'decision' => [
            'approved_subject' => 'Zugriff freigegeben - :app',
            'approved_body' => 'Ihr Zugriff auf :app wurde freigegeben. Sie können sich jetzt anmelden.',
            'rejected_subject' => 'Zugriff nicht freigegeben - :app',
            'rejected_body' => 'Ihre Zugriffsanfrage für :app wurde nicht freigegeben.',
            'action' => 'Anmelden',
        ],
    ],
];
