<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Daten des Jahresabschlusses geladen.',
        'saved' => 'Gespeichert.',
        'deleted' => 'Gelöscht.',
        'transitioned' => 'Status aktualisiert.',
        'generated' => 'Die Abschlussrechnungen wurden mit der Rundungsquadratur erstellt.',
        'cash_flow_generated' => 'Die Geldflussrechnung wurde erstellt.',
        'notes_populated' => 'Der Anhang wurde aus den Nebenbüchern befüllt.',
        'xbrl_exported' => 'Die XBRL-Instanz wurde exportiert und validiert.',
        'pdfa_generated' => 'Die PDF/A-Dokumente wurden erstellt und geprüft.',
        'pdfa_verified' => 'Das PDF/A-Dokument wurde geprüft.',
        'indicators_computed' => 'Die Kennzahlen und Krisenindikatoren wurden berechnet.',
    ],
    'errors' => [
        'unsupported_standard' => 'Der Rechnungslegungsstandard :standard wird nicht unterstützt.',
        'fiscal_year_not_found' => 'Das Geschäftsjahr wurde nicht gefunden.',
        'comparative_year_same' => 'Das Vergleichsjahr muss vom Geschäftsjahr abweichen.',
        'income_statement_method_not_allowed' => 'Das Erfolgsrechnungsverfahren :method ist für :standard nicht zulässig.',
        'transition_not_allowed' => 'Ein Dossier im Status :from kann nicht in :to wechseln.',
        'filing_requires_valid_xbrl' => 'Die Hinterlegung erfordert eine exportierte XBRL-Instanz ohne Validierungsfehler.',
        'dossier_locked' => 'Das Dossier ist gesperrt und kann nicht mehr geändert werden.',
        'statements_not_generated' => 'Zuerst die Abschlussrechnungen erstellen.',
        'notes_not_populated' => 'Zuerst den Anhang befüllen.',
        'artifact_missing' => 'Die gespeicherte Datei ist nicht mehr verfügbar.',
        'artifact_not_pdfa' => 'Die Datei ist kein PDF/A-Dokument.',
        'pdfa_kind_unknown' => 'Die Dokumentart :kind ist unbekannt.',
        'pdfa_not_compliant' => 'Das erstellte Dokument :kind hat die PDF/A-Prüfung nicht bestanden und wurde nicht gespeichert.',
        'pdfa_font_missing' => 'Die Schriftdatei :path für die PDF/A-Dokumente fehlt.',
        'pdfa_font_invalid' => 'Die Schriftdatei für die PDF/A-Dokumente ist ungültig (:table).',
        'pdfa_profile_missing' => 'Das ICC-Farbprofil für die PDF/A-Dokumente fehlt.',
        'pdfa_profile_unsupported' => 'Das PDF/A-Profil :profile wird nicht unterstützt.',
    ],
];
