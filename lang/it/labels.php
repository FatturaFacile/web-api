<?php

declare(strict_types=1);

return [

    'title' => 'Etichette prodotto',

    'fields' => [
        'sku' => 'SKU',
        'brand' => 'Marca',
    ],

    'price' => [
        'including_tax' => 'IVA incl.',
        'excluding_tax' => 'IVA escl.',
    ],

    'errors' => [
        'unknown_preset' => 'Il preset di etichette ":preset" non esiste.',
        'preset_required' => 'Scegli un preset di etichette o imposta un preset predefinito nelle preferenze delle etichette.',
        'preset_key_reserved' => 'La chiave del preset ":key" è riservata a un preset di sistema.',
        'preset_key_invalid' => 'La chiave del preset ":key" non è valida: usa lettere minuscole, cifre e trattini bassi (massimo 50 caratteri).',
        'layout_does_not_fit' => 'La griglia di etichette non entra nella pagina: controlla formato pagina, margini, spaziatura e dimensione delle etichette.',
        'offset_too_large' => 'Lo scostamento iniziale deve essere inferiore al numero di etichette per pagina (:count).',
        'too_many_labels' => 'Una singola richiesta può stampare al massimo :max etichette.',
        'products_not_encodable' => 'Alcuni prodotti non possono essere codificati con il tipo di codice a barre selezionato.',
        'product_not_encodable' => 'Il prodotto ":sku" non può essere codificato: :reason',

        'barcode' => [
            'empty_value' => "Non c'è alcun valore da codificare.",
            'invalid_characters' => 'Il valore contiene caratteri che il tipo di codice a barre selezionato non può codificare.',
            'invalid_length' => 'Il valore ha una lunghezza non ammessa dal tipo di codice a barre selezionato.',
            'invalid_checksum' => 'La cifra di controllo del valore non è corretta.',
            'unsupported_type' => 'Il tipo di codice a barre non è supportato.',
            'unsupported_format' => 'Il formato di output non è supportato; usa svg o png.',
            'rendering_failed' => 'Non è stato possibile generare il codice a barre.',
        ],
    ],

    'problem_titles' => [
        'barcode' => 'Impossibile generare il codice a barre',
        'labels' => 'Impossibile stampare le etichette',
    ],

    'messages' => [
        'presets' => 'Preset di etichette recuperati con successo',
    ],

];
