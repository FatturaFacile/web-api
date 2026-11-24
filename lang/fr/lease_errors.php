<?php

declare(strict_types=1);

return [
    'contract' => [
        'transition' => 'Le contrat de location est :status : il ne peut pas devenir :target.',
        'only_draft_editable' => 'Le contrat de location est :status : seul un brouillon peut être modifié. Pour changer un contrat actif, utilisez une réévaluation.',
        'only_draft_deletable' => 'Le contrat de location est :status : seul un brouillon peut être supprimé.',
        'not_active' => 'Le contrat de location est :status : il doit être actif.',
        'exempt_not_measured' => 'Un contrat de courte durée ou de faible valeur avec exemption est passé en charges : il n\'a ni évaluation ni échéancier.',
        'negative_rou' => 'Le droit d\'utilisation ne serait pas positif : les avantages reçus dépassent la dette locative.',
        'term_too_long' => 'La durée ne peut pas dépasser :max mois.',
        'term_multiple' => 'La durée de :term mois doit être un nombre entier de périodes de paiement de :frequency mois.',
        'extension_required' => 'Indiquez les mois de la prolongation lorsqu\'elle sera raisonnablement certainement exercée.',
        'purchase_option_required' => 'Une option d\'achat raisonnablement certaine d\'être exercée exige son prix et la durée d\'utilité du bien.',
        'useful_life_short' => 'La durée d\'utilité du bien ne peut pas être inférieure à la durée du contrat.',
        'short_term_limit' => 'Un contrat de courte durée ne peut pas dépasser :max mois ni comporter une option d\'achat raisonnablement certaine d\'être exercée.',
        'low_value_required' => 'Indiquez la valeur du bien à neuf pour choisir l\'exemption des biens de faible valeur.',
        'low_value_limit' => 'Le bien vaut :value :currency à neuf : l\'exemption de faible valeur admet jusqu\'à :max :currency.',
    ],
    'remeasure' => [
        'effective_not_period_start' => 'La date d\'effet doit être le premier jour d\'un mois de l\'échéancier.',
        'period_already_posted' => 'Les périodes jusqu\'à :period sont déjà comptabilisées : la réévaluation doit prendre effet à partir de la période :next.',
        'rate_required' => 'Une modification ou une réestimation exige le taux d\'actualisation révisé.',
        'rate_not_allowed' => 'Une variation d\'indice conserve le taux d\'actualisation initial.',
        'payment_required' => 'Une variation d\'indice exige le nouveau montant du loyer.',
        'term_fixed_for_index' => 'Une variation d\'indice ne change pas la durée restante.',
        'term_zero' => 'La durée restante doit être d\'au moins un mois.',
        'term_multiple' => 'Le contrat doit se terminer après un nombre entier de périodes de paiement de :frequency mois.',
    ],
    'posting' => [
        'not_postable' => 'Le contrat de location est :status : seul un contrat actif avec échéancier peut être comptabilisé.',
    ],
];
