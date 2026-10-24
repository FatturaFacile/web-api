<?php

declare(strict_types=1);

return [

    'messages' => [
        'listed' => 'Backups retrieved successfully',
        'queued' => 'Backup queued successfully',
        'retrieved' => 'Backup retrieved successfully',
        'deleted' => 'Backup deleted successfully',
        'restored' => 'Backup restored successfully',
    ],

    'errors' => [
        'not_ready' => 'The backup is not completed yet, so it cannot be downloaded.',
        'file_missing' => 'The backup archive is no longer available in storage.',
        'in_progress' => 'The backup is being generated and cannot be deleted yet.',
        'not_restorable' => 'Only a completed backup can be restored.',
    ],

    'problem_titles' => [
        'download' => 'Backup download',
        'delete' => 'Backup deletion',
        'restore' => 'Backup restore',
    ],

    'maintenance' => [
        'title' => 'Tenant under maintenance',
        'detail' => 'The data of this organization is being restored from a backup. Try again in a few moments.',
    ],

    'command' => [
        'tenant_or_all' => 'Specify exactly one of --tenant or --all.',
        'tenant_not_found' => 'Tenant :tenant was not found.',
        'queued' => 'Backup queued for :count tenant(s).',
    ],

    'restore_errors' => [
        'unreadable_archive' => 'The file is not a readable ZIP archive.',
        'missing_manifest' => 'The archive has no manifest.json.',
        'invalid_manifest' => 'The manifest is incomplete or has been altered.',
        'tenant_mismatch' => 'The archive belongs to another organization.',
        'missing_data' => 'The archive has no data.json.',
        'checksum_mismatch' => 'The data file does not match the checksum of the manifest: the archive has been altered or is damaged.',
        'invalid_data' => 'The data file is damaged or not in the expected format.',
        'unknown_table' => 'The archive contains data for an unknown table (:table).',
        'count_mismatch' => 'The number of rows in the data file does not match the manifest.',
        'unsafe_path' => 'The archive lists an unsafe file path (:path).',
        'missing_file' => 'The archive is missing the file :path.',
        'missing_archive' => 'The backup archive is not available in storage.',
        'snapshot_failed' => 'The safety snapshot taken before the restore failed, so nothing was changed.',
    ],

];
