<?php

declare(strict_types=1);

return [

    'title' => 'Étiquettes produit',

    'fields' => [
        'sku' => 'SKU',
        'brand' => 'Marque',
    ],

    'price' => [
        'including_tax' => 'TVA incl.',
        'excluding_tax' => 'TVA excl.',
    ],

    'errors' => [
        'unknown_preset' => 'Le préréglage d\'étiquettes ":preset" n\'existe pas.',
        'preset_required' => "Choisissez un préréglage d'étiquettes ou définissez un préréglage par défaut dans les préférences d'étiquettes.",
        'preset_key_reserved' => 'La clé de préréglage ":key" est réservée à un préréglage système.',
        'preset_key_invalid' => 'La clé de préréglage ":key" n\'est pas valide : utilisez des lettres minuscules, des chiffres et des tirets bas (50 caractères maximum).',
        'layout_does_not_fit' => "La grille d'étiquettes ne tient pas sur la page : vérifiez le format de page, les marges, l'espacement et la taille des étiquettes.",
        'offset_too_large' => "Le décalage de départ doit être inférieur au nombre d'étiquettes par page (:count).",
        'too_many_labels' => 'Une seule requête peut imprimer au maximum :max étiquettes.',
        'products_not_encodable' => 'Certains produits ne peuvent pas être encodés avec le type de code-barres sélectionné.',
        'product_not_encodable' => 'Le produit ":sku" ne peut pas être encodé : :reason',

        'barcode' => [
            'empty_value' => "Il n'y a aucune valeur à encoder.",
            'invalid_characters' => 'La valeur contient des caractères que le type de code-barres sélectionné ne peut pas encoder.',
            'invalid_length' => "La valeur a une longueur que le type de code-barres sélectionné n'autorise pas.",
            'invalid_checksum' => 'Le chiffre de contrôle de la valeur est incorrect.',
            'unsupported_type' => "Le type de code-barres n'est pas pris en charge.",
            'unsupported_format' => "Le format de sortie n'est pas pris en charge ; utilisez svg ou png.",
            'rendering_failed' => "Le code-barres n'a pas pu être généré.",
        ],
    ],

    'problem_titles' => [
        'barcode' => 'Impossible de générer le code-barres',
        'labels' => "Impossible d'imprimer les étiquettes",
    ],

    'messages' => [
        'presets' => "Préréglages d'étiquettes récupérés avec succès",
    ],

];
