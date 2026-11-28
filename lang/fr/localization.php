<?php

declare(strict_types=1);

return [
    'messages' => [
        'dictionary' => 'Dictionnaire chargé.',
        'locale_updated' => 'Langue du profil mise à jour.',
        'settings' => 'Paramètres de localisation.',
        'settings_updated' => 'Paramètres de localisation mis à jour.',
        'translations' => 'Traductions chargées.',
        'translation_saved' => 'Traduction enregistrée.',
        'import_preview' => 'Aperçu des différences de l’importation.',
        'import_done' => 'Traductions importées.',
        'resource_translations' => 'Traductions de l’enregistrement.',
        'resource_translations_saved' => 'Traductions de l’enregistrement enregistrées.',
        'document_locale' => 'Langue du document.',
        'document_locale_updated' => 'Langue du document mise à jour.',
    ],
    'validation' => [
        'key_format' => 'La clé doit avoir la forme groupe.segment (lettres, chiffres, tirets et points).',
        'default_not_active' => 'La langue par défaut doit être l’une des langues actives.',
        'separators_differ' => 'Le séparateur décimal et celui des milliers doivent être différents.',
        'unknown_locale' => 'La langue :locale n’est pas prise en charge.',
        'unknown_field' => 'Le champ :field ne peut pas être traduit pour cette ressource.',
    ],
    'locked' => [
        'title' => 'Clé verrouillée',
        'detail' => 'La clé :key est un texte légal du système et ne peut pas être modifiée.',
    ],
    'import' => [
        'has_errors_title' => 'Importation invalide',
        'has_errors' => 'Le fichier contient des lignes en erreur : aucune traduction n’a été importée.',
        'errors' => [
            'invalid_key' => 'Clé invalide : utiliser la forme groupe.segment.',
            'unknown_key' => 'Clé inconnue : elle n’existe pas dans le dictionnaire du système.',
            'value_too_long' => 'Le texte dépasse 5000 caractères.',
            'locked_key' => 'Clé verrouillée : texte légal non modifiable.',
        ],
    ],
    'resource' => [
        'read_only_title' => 'Enregistrement partagé',
        'read_only_detail' => 'Cet enregistrement est partagé par toutes les entreprises et ne peut pas être traduit par votre entreprise.',
    ],
    'document' => [
        'locale_frozen' => 'La langue d’impression d’un document émis ne peut pas être modifiée.',
    ],
    'translation_print' => [
        'unavailable_title' => 'Traduction non disponible',
        'unavailable' => 'La traduction italienne n’est disponible que pour les factures émises par une entreprise italienne dans une langue autre que l’italien.',
    ],
];
