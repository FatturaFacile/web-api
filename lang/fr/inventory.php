<?php

declare(strict_types=1);

return [
    'messages' => [
        'product_updated' => 'Article mis à jour avec succès',
        'movement_created' => 'Mouvement de stock enregistré avec succès',
        'stock_reserved' => 'Stock réservé avec succès',
        'stock_released' => 'Réservation de stock libérée',
        'count_completed' => 'Inventaire physique validé avec succès',
        'adjustment_posted' => 'Ajustement d\'inventaire comptabilisé avec succès',
    ],

    'variant_inactive' => 'La variante :sku est désactivée : réactivez-la avant d\'enregistrer une entrée de stock.',
    'parent_is_variant' => 'Une variante ne peut pas être parente d\'autres variantes.',
    'duplicate_combination' => 'Une variante avec les mêmes attributs existe déjà pour cet article.',
    'attributes_required' => 'Au moins un attribut est requis.',
    'attributes_max' => 'Une variante peut combiner au maximum :max attributs.',
    'attribute_value_invalid' => 'La valeur de l\'attribut ":attribute" doit être un texte non vide d\'au plus 100 caractères.',
    'attribute_name_invalid' => 'Le nom de l\'attribut doit être un texte non vide d\'au plus 50 caractères.',
    'attribute_values_duplicated' => 'L\'attribut ":attribute" liste la même valeur plusieurs fois.',
    'too_many_combinations' => 'La matrice générerait :count variantes ; le maximum autorisé est :max.',
    'price_modifier_invalid' => 'Le modificateur de prix produirait un prix négatif.',
    'variant_has_references' => 'La variante est référencée par des mouvements de stock et ne peut être supprimée.',
    'barcode_generation_failed' => 'Impossible de générer un code-barres unique.',
    'variant_name' => ':name (:attributes)',
];
