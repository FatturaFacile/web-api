<?php

declare(strict_types=1);

return [
    'messages' => [
        'product_updated' => 'Artikel erfolgreich aktualisiert',
        'movement_created' => 'Lagerbewegung erfolgreich gebucht',
        'stock_reserved' => 'Bestand erfolgreich reserviert',
        'stock_released' => 'Bestandsreservierung aufgehoben',
        'count_completed' => 'Inventur erfolgreich abgeschlossen',
        'adjustment_posted' => 'Lagerkorrektur erfolgreich gebucht',
    ],

    'variant_inactive' => 'Die Variante :sku ist deaktiviert: bitte reaktivieren vor Bestandseinbuchung.',
    'parent_is_variant' => 'Eine Variante kann kein übergeordneter Artikel sein.',
    'duplicate_combination' => 'Eine Variante mit denselben Attributen existiert bereits.',
    'attributes_required' => 'Mindestens ein Attribut ist erforderlich.',
    'attributes_max' => 'Eine Variante darf maximal :max Attribute kombinieren.',
    'attribute_value_invalid' => 'Der Attributwert muss ein nicht-leerer Text mit max. 100 Zeichen sein.',
    'attribute_name_invalid' => 'Der Attributname muss ein nicht-leerer Text mit max. 50 Zeichen sein.',
    'attribute_values_duplicated' => 'Das Attribut ":attribute" enthält doppelte Werte.',
    'too_many_combinations' => 'Die Matrix würde :count Varianten erzeugen; das Maximum ist :max.',
    'price_modifier_invalid' => 'Der Preismodifikator würde zu einem negativen Preis führen.',
    'variant_has_references' => 'Die Variante wird in Lagerbewegungen oder Belegen referenziert und kann nicht gelöscht werden.',
    'barcode_generation_failed' => 'Eindeutiger Barcode konnte nicht generiert werden.',
    'variant_name' => ':name (:attributes)',
];
