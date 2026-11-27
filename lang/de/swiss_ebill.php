<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Datensätze erfolgreich abgerufen.',
        'loaded' => 'Datensatz erfolgreich abgerufen.',
        'saved' => 'Die eBill-Einstellungen wurden gespeichert.',
        'connection_ok' => 'Das eBill-Gateway ist erreichbar und hat die Zugangsdaten akzeptiert.',
        'connection_failed' => 'Der Test des eBill-Gateways ist fehlgeschlagen.',
        'secret_rotated' => 'Ein neues Webhook-Secret wurde erstellt. Kopieren Sie es jetzt: Es wird nicht mehr angezeigt.',
        'participant_found' => 'Der Teilnehmer ist im eBill-Verzeichnis eingetragen.',
        'participant_not_found' => 'Im eBill-Verzeichnis gibt es keinen Teilnehmer zu dieser Kennung.',
        'customer_synced' => 'Der eBill-Status des Kunden wurde geprüft.',
        'customer_linked' => 'Der Kunde wurde mit dem eBill-Teilnehmer verknüpft.',
        'bulk_verify_queued' => 'Die Prüfung der Kunden läuft im Hintergrund.',
        'transmission_queued' => 'Die Rechnung wurde für den eBill-Versand eingereiht.',
        'transmission_cancelled' => 'Die eBill wurde zurückgerufen.',
        'webhook_processed' => 'Die Benachrichtigungen wurden verarbeitet.',
    ],

    'errors' => [
        'ebill_disabled' => 'eBill ist für dieses Unternehmen nicht aktiviert.',
        'not_configured' => 'eBill ist für dieses Unternehmen nicht konfiguriert.',
        'already_transmitted' => 'Diese Rechnung hat bereits eine aktive eBill-Übermittlung.',
        'cannot_revise' => 'Nur eine abgelehnte, fehlgeschlagene oder stornierte Übermittlung kann überarbeitet werden.',
        'cannot_retry' => 'Nur eine technisch fehlgeschlagene Übermittlung kann wiederholt werden.',
        'cannot_cancel' => 'Diese eBill kann nicht mehr zurückgerufen werden.',
        'gateway_error' => 'Das eBill-Gateway hat die Anfrage abgelehnt.',
        'gateway_unavailable' => 'Das eBill-Gateway ist derzeit nicht erreichbar. Versuchen Sie es später erneut.',
        'payload_invalid' => 'Die eBill kann nicht erstellt werden: Pflichtangaben fehlen oder sind ungültig.',
        'invalid_transition' => 'Die eBill kann von ihrem aktuellen Status nicht in diesen Status wechseln.',
        'invalid_signature' => 'Die Webhook-Signatur ist ungültig.',
        'unknown_webhook' => 'Unbekannter Webhook-Endpunkt.',
        'unknown_transaction' => 'Keine eBill trägt diese Transaktions-ID.',
        'payload_unreadable' => 'Der Webhook-Inhalt ist nicht lesbar.',
        'participant_not_found' => 'Der Teilnehmer ist nicht im eBill-Verzeichnis.',
        'invalid_lookup_value' => 'Die Kennung ist für diesen Suchtyp ungültig.',
        'payload_unavailable' => 'Für diese eBill ist kein Payload gespeichert.',
        'sandbox_not_allowed' => 'Der Sandbox-Simulator ist in der Produktion nicht verfügbar.',
    ],

    'notification' => [
        'rejected_subject' => 'eBill vom Kunden abgelehnt: Rechnung :number',
        'rejected_line' => 'Der Kunde :customer hat die eBill der Rechnung :number im E-Banking abgelehnt.',
        'rejected_code' => 'Ablehnungscode: :code',
        'rejected_reason' => 'Vom Kunden angegebener Grund: :reason',
        'rejected_action' => 'Öffnen Sie die Rechnung, korrigieren Sie das Nötige und wählen Sie «eBill überarbeiten und erneut senden».',
    ],
];
