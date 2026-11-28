<?php

declare(strict_types=1);

return [
    'messages' => [
        'dictionary' => 'Wörterbuch geladen.',
        'locale_updated' => 'Profilsprache aktualisiert.',
        'settings' => 'Lokalisierungseinstellungen.',
        'settings_updated' => 'Lokalisierungseinstellungen aktualisiert.',
        'translations' => 'Übersetzungen geladen.',
        'translation_saved' => 'Übersetzung gespeichert.',
        'import_preview' => 'Vorschau der Importunterschiede.',
        'import_done' => 'Übersetzungen importiert.',
        'resource_translations' => 'Übersetzungen des Datensatzes.',
        'resource_translations_saved' => 'Übersetzungen des Datensatzes gespeichert.',
        'document_locale' => 'Dokumentsprache.',
        'document_locale_updated' => 'Dokumentsprache aktualisiert.',
    ],
    'validation' => [
        'key_format' => 'Der Schlüssel muss die Form Gruppe.Segment haben (Buchstaben, Ziffern, Bindestriche und Punkte).',
        'default_not_active' => 'Die Standardsprache muss eine der aktiven Sprachen sein.',
        'separators_differ' => 'Dezimal- und Tausendertrennzeichen müssen verschieden sein.',
        'unknown_locale' => 'Die Sprache :locale wird nicht unterstützt.',
        'unknown_field' => 'Das Feld :field kann für diese Ressource nicht übersetzt werden.',
    ],
    'locked' => [
        'title' => 'Gesperrter Schlüssel',
        'detail' => 'Der Schlüssel :key ist ein gesetzlicher Systemtext und kann nicht geändert werden.',
    ],
    'import' => [
        'has_errors_title' => 'Ungültiger Import',
        'has_errors' => 'Die Datei enthält fehlerhafte Zeilen: es wurde keine Übersetzung importiert.',
        'errors' => [
            'invalid_key' => 'Ungültiger Schlüssel: die Form Gruppe.Segment verwenden.',
            'unknown_key' => 'Unbekannter Schlüssel: er existiert nicht im Systemwörterbuch.',
            'value_too_long' => 'Der Text überschreitet 5000 Zeichen.',
            'locked_key' => 'Gesperrter Schlüssel: gesetzlicher Text, nicht änderbar.',
        ],
    ],
    'resource' => [
        'read_only_title' => 'Gemeinsamer Datensatz',
        'read_only_detail' => 'Dieser Datensatz wird von allen Unternehmen geteilt und kann von Ihrem Unternehmen nicht übersetzt werden.',
    ],
    'document' => [
        'locale_frozen' => 'Die Dokumentsprache eines ausgestellten Dokuments kann nicht geändert werden.',
    ],
    'translation_print' => [
        'unavailable_title' => 'Übersetzung nicht verfügbar',
        'unavailable' => 'Die italienische Übersetzung ist nur für Rechnungen verfügbar, die ein italienisches Unternehmen in einer anderen Sprache als Italienisch ausgestellt hat.',
    ],
];
