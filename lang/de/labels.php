<?php

declare(strict_types=1);

return [

    'title' => 'Produktetiketten',

    'fields' => [
        'sku' => 'SKU',
        'brand' => 'Marke',
    ],

    'price' => [
        'including_tax' => 'inkl. MWST',
        'excluding_tax' => 'exkl. MWST',
    ],

    'errors' => [
        'unknown_preset' => 'Die Etikettenvorlage ":preset" existiert nicht.',
        'preset_required' => 'Wähle eine Etikettenvorlage oder lege in den Etiketteneinstellungen eine Standardvorlage fest.',
        'preset_key_reserved' => 'Der Vorlagenschlüssel ":key" ist für eine Systemvorlage reserviert.',
        'preset_key_invalid' => 'Der Vorlagenschlüssel ":key" ist ungültig: Verwende Kleinbuchstaben, Ziffern und Unterstriche (maximal 50 Zeichen).',
        'layout_does_not_fit' => 'Das Etikettenraster passt nicht auf die Seite: Prüfe Seitenformat, Ränder, Abstände und Etikettengrösse.',
        'offset_too_large' => 'Der Startversatz muss kleiner sein als die Anzahl Etiketten pro Seite (:count).',
        'too_many_labels' => 'Eine einzelne Anfrage kann höchstens :max Etiketten drucken.',
        'products_not_encodable' => 'Einige Produkte können mit dem gewählten Barcode-Typ nicht codiert werden.',
        'product_not_encodable' => 'Das Produkt ":sku" kann nicht codiert werden: :reason',

        'barcode' => [
            'empty_value' => 'Es gibt keinen Wert zum Codieren.',
            'invalid_characters' => 'Der Wert enthält Zeichen, die der gewählte Barcode-Typ nicht codieren kann.',
            'invalid_length' => 'Der Wert hat eine Länge, die der gewählte Barcode-Typ nicht zulässt.',
            'invalid_checksum' => 'Die Prüfziffer des Werts ist falsch.',
            'unsupported_type' => 'Der Barcode-Typ wird nicht unterstützt.',
            'unsupported_format' => 'Das Ausgabeformat wird nicht unterstützt; verwende svg oder png.',
            'rendering_failed' => 'Der Barcode konnte nicht erzeugt werden.',
        ],
    ],

    'problem_titles' => [
        'barcode' => 'Barcode kann nicht erzeugt werden',
        'labels' => 'Etiketten können nicht gedruckt werden',
    ],

    'messages' => [
        'presets' => 'Etikettenvorlagen erfolgreich abgerufen',
    ],

];
