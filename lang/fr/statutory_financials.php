<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Données des comptes annuels chargées.',
        'saved' => 'Enregistré.',
        'deleted' => 'Supprimé.',
        'transitioned' => 'Statut mis à jour.',
        'generated' => 'Les états ont été générés avec la quadrature des arrondis.',
        'cash_flow_generated' => 'Le tableau des flux de trésorerie a été généré.',
        'notes_populated' => 'L\'annexe a été renseignée à partir des sous-comptes.',
        'xbrl_exported' => 'L\'instance XBRL a été exportée et validée.',
        'pdfa_generated' => 'Les documents PDF/A ont été générés et vérifiés.',
        'pdfa_verified' => 'Le document PDF/A a été vérifié.',
        'indicators_computed' => 'Les ratios et les indicateurs de crise ont été calculés.',
    ],
    'errors' => [
        'unsupported_standard' => 'Le référentiel comptable :standard n\'est pas pris en charge.',
        'fiscal_year_not_found' => 'L\'exercice est introuvable.',
        'comparative_year_same' => 'L\'exercice comparatif doit être différent de l\'exercice.',
        'income_statement_method_not_allowed' => 'La méthode de compte de résultat :method n\'est pas autorisée pour :standard.',
        'transition_not_allowed' => 'Un dossier au statut :from ne peut pas passer à :to.',
        'filing_requires_valid_xbrl' => 'Le dépôt exige une instance XBRL exportée sans erreur de validation.',
        'dossier_locked' => 'Le dossier est verrouillé et ne peut plus être modifié.',
        'statements_not_generated' => 'Générez d\'abord les états.',
        'notes_not_populated' => 'Renseignez d\'abord l\'annexe.',
        'artifact_missing' => 'Le fichier enregistré n\'est plus disponible.',
        'artifact_not_pdfa' => 'Le fichier n\'est pas un document PDF/A.',
        'pdfa_kind_unknown' => 'Le type de document :kind est inconnu.',
        'pdfa_not_compliant' => 'Le document :kind généré n\'a pas réussi la vérification PDF/A et n\'a pas été enregistré.',
        'pdfa_font_missing' => 'Le fichier de police :path pour les documents PDF/A est manquant.',
        'pdfa_font_invalid' => 'Le fichier de police pour les documents PDF/A est invalide (:table).',
        'pdfa_profile_missing' => 'Le profil colorimétrique ICC pour les documents PDF/A est manquant.',
        'pdfa_profile_unsupported' => 'Le profil PDF/A :profile n\'est pas pris en charge.',
    ],
];
