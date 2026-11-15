<?php

declare(strict_types=1);

return [
    'formula' => [
        'length' => 'La formule ne peut pas être vide et peut avoir au plus :max caractères.',
        'number' => 'Le nombre ":token" n\'est pas valide : utilisez des chiffres avec au plus 8 décimales.',
        'character' => 'Le caractère ":character" n\'est pas autorisé dans une formule : utilisez des nombres, des clés de mesure, + - * / et des parenthèses.',
        'incomplete' => 'La formule est incomplète.',
        'parenthesis' => 'Une parenthèse n\'est pas fermée.',
        'unexpected' => '":token" inattendu dans la formule.',
        'too_deep' => 'La formule a trop de niveaux imbriqués.',
        'no_reference' => 'La formule doit utiliser au moins une mesure du jeu de données.',
        'unknown_measure' => 'La formule utilise ":measure", qui n\'est pas une mesure du jeu de données.',
    ],
    'calculated' => [
        'key_taken_by_dataset' => 'La clé ":key" est déjà une mesure ou une dimension du jeu de données.',
        'key_exists' => 'Une mesure calculée avec la clé ":key" existe déjà sur ce jeu de données.',
        'limit' => 'Un jeu de données peut avoir au plus :max mesures calculées.',
        'in_use' => 'La mesure est encore utilisée par : :usage. Retirez-la d\'abord de là.',
    ],
    'query' => [
        'unknown_calculated' => 'Cette mesure calculée n\'existe pas sur le jeu de données.',
    ],
    'pivot' => [
        'measure_missing' => 'La valeur du tableau croisé doit être une mesure de la requête.',
        'dimension_missing' => 'La ligne ou la colonne du tableau croisé doit être une dimension de la requête.',
        'dimension_repeated' => 'La dimension ":key" est utilisée deux fois dans le tableau croisé.',
        'no_values' => 'Choisissez au moins une mesure pour les cellules du tableau croisé.',
        'no_axis' => 'Choisissez au moins une dimension pour les lignes ou les colonnes du tableau croisé.',
        'too_large' => 'Le tableau croisé aurait plus de :max cellules : filtrez ou regroupez davantage les données.',
    ],
    'widget' => [
        'limit' => 'Un tableau de bord peut avoir au plus :max widgets.',
        'source_required' => 'Choisissez une requête enregistrée ou indiquez un jeu de données et une spécification.',
        'source_conflict' => 'Utilisez soit une requête enregistrée, soit un jeu de données avec une spécification, pas les deux.',
        'saved_query_missing' => 'La requête enregistrée n\'existe pas ou vous ne pouvez pas l\'ouvrir.',
        'grid_bounds' => 'Le widget doit rester dans les :columns colonnes de la grille.',
        'overlap' => 'Le widget chevauche un autre widget du tableau de bord.',
        'not_in_dashboard' => 'Ce widget n\'appartient pas au tableau de bord.',
        'drill_too_deep' => 'Il n\'y a pas de niveau supplémentaire à explorer.',
        'drill_mismatch' => 'Le drill-down doit suivre le chemin : la dimension ":expected" était attendue.',
        'drill_path_unknown' => 'Le chemin de drill-down doit nommer des dimensions du jeu de données.',
        'drill_path_date' => 'La dimension date ":key" ne peut pas figurer dans un chemin de drill-down.',
        'drill_path_repeated' => 'La dimension ":key" est répétée dans le chemin de drill-down.',
    ],
    'share' => [
        'not_member' => 'L\'utilisateur n\'est pas membre de cette entreprise.',
        'owner' => 'Le propriétaire a déjà un accès complet.',
    ],
    'snapshot' => [
        'owner_gone' => 'Le propriétaire de cette requête n\'existe plus.',
        'owner_left' => 'Le propriétaire de cette requête n\'est plus membre de l\'entreprise.',
        'not_comparable' => 'Seul un instantané réussi d\'une requête enregistrée peut être comparé à aujourd\'hui.',
    ],
];
