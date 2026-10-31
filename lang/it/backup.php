<?php

declare(strict_types=1);

return [

    'messages' => [
        'listed' => 'Backup recuperati con successo',
        'queued' => 'Backup accodato con successo',
        'retrieved' => 'Backup recuperato con successo',
        'deleted' => 'Backup eliminato con successo',
        'restored' => 'Backup ripristinato con successo',
    ],

    'errors' => [
        'not_ready' => 'Il backup non è ancora completato, quindi non può essere scaricato.',
        'file_missing' => "L'archivio di backup non è più disponibile nello storage.",
        'in_progress' => 'Il backup è in fase di generazione e non può essere ancora eliminato.',
        'not_restorable' => 'Solo un backup completato può essere ripristinato.',
    ],

    'problem_titles' => [
        'download' => 'Download backup',
        'delete' => 'Eliminazione backup',
        'restore' => 'Ripristino backup',
    ],

    'maintenance' => [
        'title' => 'Tenant in manutenzione',
        'detail' => 'I dati di questa organizzazione sono in fase di ripristino da un backup. Riprova tra qualche istante.',
    ],

    'command' => [
        'tenant_or_all' => 'Specify exactly one of --tenant or --all.',
        'tenant_not_found' => 'Tenant :tenant was not found.',
        'queued' => 'Backup queued for :count tenant(s).',
    ],

    'restore_errors' => [
        'unreadable_archive' => 'Il file non è un archivio ZIP leggibile.',
        'missing_manifest' => "L'archivio non contiene manifest.json.",
        'invalid_manifest' => 'Il manifest è incompleto o è stato modificato.',
        'tenant_mismatch' => "L'archivio appartiene a un'altra organizzazione.",
        'missing_data' => "L'archivio non contiene data.json.",
        'checksum_mismatch' => 'Il file dei dati non corrisponde al checksum del manifest: archivio alterato o danneggiato.',
        'invalid_data' => 'Il file dei dati è danneggiato o non nel formato previsto.',
        'unknown_table' => 'L\'archivio contiene dati per una tabella sconosciuta (:table).',
        'count_mismatch' => 'Il numero di righe nel file dei dati non corrisponde al manifest.',
        'unsafe_path' => 'L\'archivio elenca un percorso file non sicuro (:path).',
        'missing_file' => 'All\'archivio manca il file :path.',
        'missing_archive' => "L'archivio di backup non è disponibile nello storage.",
        'snapshot_failed' => 'Lo snapshot di sicurezza prima del ripristino è fallito, nessuna modifica apportata.',
    ],

];
