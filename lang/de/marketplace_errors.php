<?php

declare(strict_types=1);

return [
    'installation' => [
        'unknown_connector' => 'Dieser Konnektor ist nicht im Katalog.',
        'limit' => 'Ein Unternehmen kann höchstens :max Installationen desselben Konnektors haben.',
        'name_taken' => 'Eine Installation dieses Konnektors mit diesem Namen existiert bereits.',
        'scopes_missing' => 'Der Konnektor benötigt diese Berechtigungsbereiche: :scopes.',
        'scope_not_allowed' => 'Der Bereich ":scope" ist ungültig oder du kannst ihn nicht erteilen.',
        'not_configured' => 'Konfiguriere die Installation zuerst.',
        'unhealthy' => 'Die Zustandsprüfung ist fehlgeschlagen, daher wurde die Installation nicht aktiviert: :message',
        'up_to_date' => 'Die Installation verwendet bereits die neueste Version des Konnektors.',
        'run_in_progress' => 'Eine Synchronisierung läuft: Warte, bis sie fertig ist, bevor du deinstallierst.',
        'rate_limit_above_max' => 'Das Limit darf nicht über :max Aufrufen pro Minute liegen, dem Maximum dieses Konnektors.',
    ],
    'config' => [
        'unknown_field' => 'Dieses Feld gehört nicht zur Konfiguration des Konnektors.',
        'required' => 'Das Feld ":field" ist erforderlich.',
        'invalid_text' => 'Das Feld ":field" muss ein Text mit höchstens 255 Zeichen sein.',
        'invalid_secret' => 'Das Feld ":field" muss ein Geheimnis mit mindestens 4 Zeichen sein.',
        'invalid_number' => 'Das Feld ":field" muss eine Zahl sein.',
        'invalid_boolean' => 'Das Feld ":field" muss wahr oder falsch sein.',
        'invalid_option' => 'Das Feld ":field" hat einen nicht erlaubten Wert.',
        'invalid_url' => 'Das Feld ":field" muss eine öffentliche https-Adresse sein.',
    ],
    'health' => [
        'unreachable' => 'Das externe System hat nicht geantwortet.',
        'address_refused' => 'Die Adresse des externen Systems ist nicht erlaubt.',
        'egress_blocked' => 'Die Datenresidenz-Richtlinie des Unternehmens erlaubt keine Datenübermittlung in das Land des externen Systems.',
        'failed' => 'Die Zustandsprüfung konnte nicht abgeschlossen werden.',
        'credentials_rejected' => 'Das externe System hat die Zugangsdaten abgelehnt.',
        'rate_limited' => 'Das externe System begrenzt die Anfragen.',
        'server_error' => 'Das externe System antwortete mit einem Fehler (:status).',
        'unexpected_status' => 'Das externe System antwortete mit einem unerwarteten Status (:status).',
    ],
    'sync' => [
        'not_enabled' => 'Aktiviere die Installation, bevor du sie synchronisierst.',
        'unknown_resource' => 'Dieser Konnektor hat keine Ressource ":resource".',
        'rate_limited' => 'Das Anfragelimit der Installation ist ausgeschöpft: Versuche es in :seconds Sekunden erneut.',
        'already_running' => 'Diese Ressource wird bereits synchronisiert.',
        'stale' => 'Der Lauf wurde nie beendet und vom nächsten geschlossen.',
        'failed' => 'Die Synchronisierung ist fehlgeschlagen.',
        'status' => 'Das externe System antwortete mit einem Fehler (:status).',
        'credential_missing' => 'Die Zugangsdaten ":field" fehlen: Konfiguriere die Installation erneut.',
        'fetched' => ':count Datensätze von :resource abgerufen.',
        'delivered' => 'Das Ereignis wurde zugestellt.',
    ],
    'mapping' => [
        'missing_field' => 'Das Feld ":field" fehlt.',
        'invalid_amount' => 'Der Betrag ":field" ist keine Zahl.',
        'invalid_currency' => 'Die Währung ist kein gültiger ISO-Code.',
        'invalid_date' => 'Das Datum ist ungültig.',
        'invalid_lines' => 'Die Bestellpositionen sind ungültig.',
    ],
];
