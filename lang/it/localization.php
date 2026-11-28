<?php

declare(strict_types=1);

return [
    'messages' => [
        'dictionary' => 'Dizionario caricato.',
        'locale_updated' => 'Lingua del profilo aggiornata.',
        'settings' => 'Impostazioni di localizzazione.',
        'settings_updated' => 'Impostazioni di localizzazione aggiornate.',
        'translations' => 'Traduzioni caricate.',
        'translation_saved' => 'Traduzione salvata.',
        'import_preview' => 'Anteprima delle differenze dell’importazione.',
        'import_done' => 'Traduzioni importate.',
        'resource_translations' => 'Traduzioni del record.',
        'resource_translations_saved' => 'Traduzioni del record salvate.',
        'document_locale' => 'Lingua del documento.',
        'document_locale_updated' => 'Lingua del documento aggiornata.',
    ],
    'validation' => [
        'key_format' => 'La chiave deve avere la forma gruppo.segmento (lettere, cifre, trattini e punti).',
        'default_not_active' => 'La lingua predefinita deve essere una delle lingue attive.',
        'separators_differ' => 'Il separatore dei decimali e quello delle migliaia devono essere diversi.',
        'unknown_locale' => 'La lingua :locale non è supportata.',
        'unknown_field' => 'Il campo :field non è traducibile per questa risorsa.',
    ],
    'locked' => [
        'title' => 'Chiave bloccata',
        'detail' => 'La chiave :key è un testo normativo di sistema e non può essere modificata.',
    ],
    'import' => [
        'has_errors_title' => 'Importazione non valida',
        'has_errors' => 'Il file contiene righe con errori: nessuna traduzione è stata importata.',
        'errors' => [
            'invalid_key' => 'Chiave non valida: usare la forma gruppo.segmento.',
            'unknown_key' => 'Chiave sconosciuta: non esiste nel dizionario di sistema.',
            'value_too_long' => 'Il testo supera i 5000 caratteri.',
            'locked_key' => 'Chiave bloccata: testo normativo non modificabile.',
        ],
    ],
    'resource' => [
        'read_only_title' => 'Record condiviso',
        'read_only_detail' => 'Questo record è condiviso da tutte le aziende e non può essere tradotto dalla tua azienda.',
    ],
    'document' => [
        'locale_frozen' => 'La lingua di stampa di un documento emesso non può essere modificata.',
    ],
    'translation_print' => [
        'unavailable_title' => 'Traduzione non disponibile',
        'unavailable' => 'La traduzione italiana è disponibile solo per fatture emesse da un’azienda italiana in una lingua diversa dall’italiano.',
    ],
];
