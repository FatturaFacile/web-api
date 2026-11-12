<?php

declare(strict_types=1);

return [

    'messages' => [
        'listed' => 'Sauvegardes récupérées avec succès',
        'queued' => 'Sauvegarde mise en file d\'attente avec succès',
        'retrieved' => 'Sauvegarde récupérée avec succès',
        'deleted' => 'Sauvegarde supprimée avec succès',
        'restored' => 'Sauvegarde restaurée avec succès',
    ],

    'errors' => [
        'not_ready' => 'La sauvegarde n\'est pas encore terminée et ne peut donc pas être téléchargée.',
        'file_missing' => 'L\'archive de sauvegarde n\'est plus disponible dans le stockage.',
        'in_progress' => 'La sauvegarde est en cours de génération et ne peut pas encore être supprimée.',
        'not_restorable' => 'Seule une sauvegarde terminée peut être restaurée.',
    ],

    'problem_titles' => [
        'download' => 'Téléchargement de la sauvegarde',
        'delete' => 'Suppression de la sauvegarde',
        'restore' => 'Restauration de la sauvegarde',
    ],

    'maintenance' => [
        'title' => 'Tenant en maintenance',
        'detail' => 'Les données de cette organisation sont en cours de restauration à partir d\'une sauvegarde. Réessayez dans quelques instants.',
    ],

    'command' => [
        'tenant_or_all' => 'Specify exactly one of --tenant or --all.',
        'tenant_not_found' => 'Tenant :tenant was not found.',
        'queued' => 'Backup queued for :count tenant(s).',
    ],

    'restore_errors' => [
        'unreadable_archive' => 'Le fichier n\'est pas une archive ZIP lisible.',
        'missing_manifest' => 'L\'archive ne contient pas de manifest.json.',
        'invalid_manifest' => 'Le manifeste est incomplet ou a été modifié.',
        'tenant_mismatch' => 'L\'archive appartient à une autre organisation.',
        'missing_data' => 'L\'archive ne contient pas de data.json.',
        'checksum_mismatch' => 'Le fichier de données ne correspond pas à la somme de contrôle du manifeste : l\'archive a été modifiée ou est endommagée.',
        'invalid_data' => 'Le fichier de données est endommagé ou n\'a pas le format attendu.',
        'unknown_table' => 'L\'archive contient des données pour une table inconnue (:table).',
        'count_mismatch' => 'Le nombre de lignes du fichier de données ne correspond pas au manifeste.',
        'unsafe_path' => 'L\'archive répertorie un chemin de fichier non sûr (:path).',
        'missing_file' => 'Il manque le fichier :path dans l\'archive.',
        'missing_archive' => 'L\'archive de sauvegarde n\'est pas disponible dans le stockage.',
        'snapshot_failed' => 'L\'instantané de sécurité effectué avant la restauration a échoué, aucune modification n\'a été apportée.',
        'unsigned_archive' => 'L\'archive ne porte pas la signature de ce serveur. Les archives créées avant l\'introduction de la signature (format 2) ne peuvent pas être restaurées : créez une nouvelle sauvegarde.',
        'invalid_signature' => 'La signature de l\'archive n\'est pas valide : l\'archive a été modifiée ou n\'a pas été créée par ce serveur.',
        'file_checksum_mismatch' => 'Le fichier :path ne correspond pas à la somme de contrôle du manifeste : l\'archive a été modifiée ou est endommagée.',
        'foreign_reference' => 'L\'archive relie des enregistrements à des données extérieures à l\'organisation (:table.:column).',
    ],

];
