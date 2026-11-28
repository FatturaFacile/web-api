<?php

declare(strict_types=1);

return [
    'messages' => [
        'saved' => 'Daten gespeichert.',
        'deleted' => 'Eintrag gelöscht.',
        'inl_generated' => 'Vorgängige INL-Meldung erstellt.',
        'inl_sent' => 'INL-Meldung als versendet erfasst.',
        'payment_posted' => 'Zahlung gebucht und F24 geplant.',
        'payments_posted' => 'Zahlungen gebucht und F24 geplant.',
        'returns_generated' => 'Jahresbescheinigungen erstellt.',
    ],
    'errors' => [
        'inl_not_applicable' => 'Nur Gelegenheitsarbeiter benötigen die INL-Meldung.',
        'inl_cancelled' => 'Eine stornierte Meldung kann nicht versendet werden.',
        'inl_notification_missing' => 'Zahlung gesperrt: Für diesen Mitarbeiter wurde keine vorgängige INL-Meldung versendet.',
        'inl_notification_late' => 'Zahlung gesperrt: Die INL-Meldung wurde nach Beginn der Tätigkeit versendet.',
        'inl_notification_expired' => 'Zahlung gesperrt: Die INL-Meldung deckt das Datum der Leistung nicht ab.',
        'recipient_inactive' => 'Der Empfänger ist nicht aktiv.',
        'service_after_payment' => 'Die Leistung darf nicht nach der Zahlung liegen.',
        'net_not_positive' => 'Der Nettobetrag ist nicht positiv.',
        'regime_locked' => 'Das Regime kann nach der Buchung von Vergütungen nicht mehr geändert werden.',
        'recipient_has_settlements' => 'Ein Empfänger mit gebuchten Vergütungen kann nicht gelöscht werden.',
        'certificate_not_applicable' => 'Nur Vertreter können die reduzierte Quellensteuer erklären.',
    ],
    'inl' => [
        'title' => 'Vorgängige Meldung selbständiger Gelegenheitsarbeit',
        'legal_basis' => 'Art. 14 D.Lgs. 81/2008 — vor Beginn der Tätigkeit an das Arbeitsinspektorat (INL) zu senden.',
        'client' => 'Auftraggeber',
        'client_tax_id' => 'Steuernummer des Auftraggebers',
        'worker' => 'Leistungserbringer',
        'worker_tax_id' => 'Steuernummer des Leistungserbringers',
        'activity' => 'Tätigkeit',
        'place' => 'Arbeitsort',
        'start' => 'Beginn',
        'end' => 'Ende',
        'compensation' => 'Voraussichtliche Vergütung',
        'recipient' => 'Empfänger',
    ],
];
