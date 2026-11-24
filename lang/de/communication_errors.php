<?php

declare(strict_types=1);

return [
    'channel' => [
        'not_configured' => 'Konfigurieren Sie den Kanal, bevor Sie ihn testen.',
    ],
    'conversation' => [
        'closed' => 'Die Unterhaltung ist geschlossen.',
    ],
    'campaign' => [
        'not_cancellable' => 'Nur ein Entwurf oder eine geplante Kampagne kann abgebrochen werden.',
        'segment_not_found' => 'Wählen Sie ein Segment, das in diesem Unternehmen existiert.',
        'locked' => 'Eine versendete oder laufende Kampagne kann nicht mehr geändert werden.',
        'segment_required' => 'Wählen Sie zuerst das Segment der Zielgruppe.',
    ],
    'template' => [
        'not_found' => 'Keine aktive Vorlage für diesen Code und Kanal.',
        'missing_variables' => 'Fehlende Variablen: :variables.',
    ],
    'webhook' => [
        'unknown_channel' => 'Kein eingehender E-Mail-Kanal gefunden.',
        'invalid_signature' => 'Ungültige oder abgelaufene Signatur.',
    ],
];
