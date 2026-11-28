<?php

declare(strict_types=1);

return [
    'messages' => [
        'dictionary' => 'Dictionary loaded.',
        'locale_updated' => 'Profile language updated.',
        'settings' => 'Localization settings.',
        'settings_updated' => 'Localization settings updated.',
        'translations' => 'Translations loaded.',
        'translation_saved' => 'Translation saved.',
        'import_preview' => 'Import difference preview.',
        'import_done' => 'Translations imported.',
        'resource_translations' => 'Translations of the record.',
        'resource_translations_saved' => 'Translations of the record saved.',
        'document_locale' => 'Document language.',
        'document_locale_updated' => 'Document language updated.',
    ],
    'validation' => [
        'key_format' => 'The key must have the form group.segment (letters, digits, hyphens and dots).',
        'default_not_active' => 'The default language must be one of the active languages.',
        'separators_differ' => 'The decimal and thousands separators must be different.',
        'unknown_locale' => 'The language :locale is not supported.',
        'unknown_field' => 'The field :field cannot be translated for this resource.',
    ],
    'locked' => [
        'title' => 'Locked key',
        'detail' => 'The key :key is a statutory system text and cannot be changed.',
    ],
    'import' => [
        'has_errors_title' => 'Invalid import',
        'has_errors' => 'The file contains rows with errors: no translation was imported.',
        'errors' => [
            'invalid_key' => 'Invalid key: use the form group.segment.',
            'unknown_key' => 'Unknown key: it does not exist in the system dictionary.',
            'value_too_long' => 'The text exceeds 5000 characters.',
            'locked_key' => 'Locked key: statutory text that cannot be changed.',
        ],
    ],
    'resource' => [
        'read_only_title' => 'Shared record',
        'read_only_detail' => 'This record is shared by every company and cannot be translated by your company.',
    ],
    'document' => [
        'locale_frozen' => 'The print language of an issued document cannot be changed.',
    ],
    'translation_print' => [
        'unavailable_title' => 'Translation not available',
        'unavailable' => 'The Italian translation is only available for invoices issued by an Italian company in a language other than Italian.',
    ],
];
