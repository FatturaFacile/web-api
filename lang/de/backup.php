<?php

declare(strict_types=1);

return [

    'messages' => [
        'listed' => 'Backups erfolgreich abgerufen',
        'queued' => 'Backup erfolgreich eingeplant',
        'retrieved' => 'Backup erfolgreich abgerufen',
        'deleted' => 'Backup erfolgreich gelöscht',
        'restored' => 'Backup erfolgreich wiederhergestellt',
    ],

    'errors' => [
        'not_ready' => 'Das Backup ist noch nicht abgeschlossen und kann daher nicht heruntergeladen werden.',
        'file_missing' => 'Das Backup-Archiv ist im Speicher nicht mehr verfügbar.',
        'in_progress' => 'Das Backup wird gerade erstellt und kann noch nicht gelöscht werden.',
        'not_restorable' => 'Nur ein abgeschlossenes Backup kann wiederhergestellt werden.',
    ],

    'problem_titles' => [
        'download' => 'Backup-Download',
        'delete' => 'Backup löschen',
        'restore' => 'Backup wiederherstellen',
    ],

    'maintenance' => [
        'title' => 'Mandant in Wartung',
        'detail' => 'Die Daten dieser Organisation werden gerade aus einem Backup wiederhergestellt. Versuchen Sie es in einigen Augenblicken erneut.',
    ],

    'command' => [
        'tenant_or_all' => 'Specify exactly one of --tenant or --all.',
        'tenant_not_found' => 'Tenant :tenant was not found.',
        'queued' => 'Backup queued for :count tenant(s).',
    ],

    'restore_errors' => [
        'unreadable_archive' => 'Die Datei ist kein lesbares ZIP-Archiv.',
        'missing_manifest' => 'Das Archiv enthält keine manifest.json.',
        'invalid_manifest' => 'Das Manifest ist unvollständig oder wurde verändert.',
        'tenant_mismatch' => 'Das Archiv gehört zu einer anderen Organisation.',
        'missing_data' => 'Das Archiv enthält keine data.json.',
        'checksum_mismatch' => 'Die Datendatei stimmt nicht mit der Prüfsumme des Manifests überein: Das Archiv wurde verändert oder ist beschädigt.',
        'invalid_data' => 'Die Datendatei ist beschädigt oder hat nicht das erwartete Format.',
        'unknown_table' => 'Das Archiv enthält Daten für eine unbekannte Tabelle (:table).',
        'count_mismatch' => 'Die Zeilenanzahl in der Datendatei stimmt nicht mit dem Manifest überein.',
        'unsafe_path' => 'Das Archiv führt einen unsicheren Dateipfad auf (:path).',
        'missing_file' => 'Im Archiv fehlt die Datei :path.',
        'missing_archive' => 'Das Backup-Archiv ist im Speicher nicht verfügbar.',
        'snapshot_failed' => 'Der Sicherheits-Snapshot vor der Wiederherstellung ist fehlgeschlagen, es wurde nichts geändert.',
        'unsigned_archive' => 'Das Archiv trägt nicht die Signatur dieses Servers. Vor der Einführung der Signatur erstellte Archive (Format 2) können nicht wiederhergestellt werden: Erstellen Sie ein neues Backup.',
        'invalid_signature' => 'Die Signatur des Archivs ist ungültig: Das Archiv wurde verändert oder nicht von diesem Server erstellt.',
        'file_checksum_mismatch' => 'Die Datei :path stimmt nicht mit der Prüfsumme des Manifests überein: Das Archiv wurde verändert oder ist beschädigt.',
        'foreign_reference' => 'Das Archiv verknüpft Datensätze mit Daten außerhalb der Organisation (:table.:column).',
    ],

];
