<?php

declare(strict_types=1);

return [
    'messages' => [
        'product_updated' => 'Articolo aggiornato con successo',
        'movement_created' => 'Movimento di magazzino registrato con successo',
        'stock_reserved' => 'Giacenza riservata con successo',
        'stock_released' => 'Giacenza rilasciata con successo',
        'count_completed' => 'Inventario fisico completato con successo',
        'adjustment_posted' => 'Rettifica inventariale contabilizzata con successo',
    ],

    'variant_inactive' => 'La variante :sku è disattivata: riattivala prima di effettuare carichi di magazzino.',
    'parent_is_variant' => 'Una variante non può essere genitore di altre varianti.',
    'duplicate_combination' => 'Una variante con gli stessi attributi esiste già per questo prodotto.',
    'attributes_required' => 'È richiesto almeno un attributo.',
    'attributes_max' => 'Una variante può combinare al massimo :max attributi.',
    'attribute_value_invalid' => 'Il valore dell\'attributo ":attribute" deve essere un testo non vuoto di massimo 100 caratteri.',
    'attribute_name_invalid' => 'I nomi degli attributi devono essere testi non vuoti di massimo 50 caratteri.',
    'attribute_values_duplicated' => 'L\'attributo ":attribute" elenca lo stesso valore più volte.',
    'too_many_combinations' => 'La matrice genererebbe :count varianti; il massimo consentito è :max.',
    'price_modifier_invalid' => 'Il modificatore di prezzo genererebbe un prezzo negativo.',
    'variant_has_references' => 'La variante è collegata a movimenti di magazzino o documenti e non può essere eliminata.',
    'barcode_generation_failed' => 'Impossibile generare un codice a barre univoco.',
    'variant_name' => ':name (:attributes)',
];
