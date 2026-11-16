<?php

declare(strict_types=1);

return [
    'obligation' => [
        'terminated_contract' => 'Un contrat résilié n\'accepte plus de nouvelles obligations.',
        'item_locked' => 'Un élément au statut :status ne peut pas être modifié.',
        'item_closed' => 'Un élément clôturé reste au registre.',
        'already_status' => 'Cet élément a déjà le statut :status.',
    ],
    'party' => [
        'role_exists' => 'Cette partie a déjà ce rôle dans le contrat.',
    ],
    'clause' => [
        'retired_no_versions' => 'Une clause retirée ne peut pas recevoir de nouvelles versions.',
        'no_version_to_approve' => 'Une clause sans version ne peut pas être approuvée.',
        'retired_no_reuse' => 'Une clause retirée ne peut pas être réutilisée.',
        'rank_used' => 'Ce rang de formulation alternative est déjà utilisé.',
        'no_fallback_rank' => 'La clause n\'a pas de formulation alternative avec ce rang.',
    ],
    'lifecycle' => [
        'locked_by_workflow' => 'Ce contrat est verrouillé par un circuit d\'approbation : son statut change à la fin du circuit.',
        'status_outside' => 'Le statut [:status] est hors du cycle de vie du contrat et ne peut pas être modifié d\'ici.',
        'transition_invalid' => 'Un contrat ne peut pas passer de :from à :to.',
        'end_date_passed' => 'La date de fin de ce contrat est dépassée : renouvelez-le avec une nouvelle date de fin au lieu de l\'activer.',
    ],
    'register' => [
        'renew_status' => 'Un contrat au statut :status ne peut pas être renouvelé.',
        'renew_no_end' => 'Ce contrat n\'a pas de date de fin à prolonger.',
        'renew_no_term' => 'Ce contrat n\'a pas de durée de renouvellement : indiquez la nouvelle date de fin.',
        'renew_end_after' => 'La nouvelle date de fin doit être postérieure à la date de fin actuelle.',
    ],
    'template' => [
        'unapproved_clauses' => 'Seules des clauses approuvées peuvent être utilisées dans un modèle actif : :codes.',
        'wrong_kind' => 'Le modèle est destiné aux contrats de type :kind.',
        'inactive' => 'Seul un modèle actif peut être utilisé.',
        'clause_not_approved' => 'Chaque clause du modèle doit être approuvée : :clause.',
        'undeclared_placeholders' => 'Espaces réservés non déclarés dans les clauses : :names.',
        'missing_variables' => 'Variables obligatoires manquantes : :names.',
    ],
    'redline' => [
        'same_contract' => 'Les deux versions doivent appartenir au même contrat.',
        'comment_resolved' => 'Le commentaire est déjà résolu.',
        'round_open' => 'Clôturez d\'abord le tour de négociation ouvert.',
        'round_closed' => 'Le tour est déjà clôturé.',
    ],
    'signature' => [
        'version_mismatch' => 'La version n\'appartient pas à ce contrat.',
        'pending_exists' => 'Cette version a déjà une demande de signature en cours.',
        'only_draft_send' => 'Seule une demande en brouillon peut être envoyée.',
        'already_signed' => 'Ce signataire a déjà signé.',
        'sign_in_order' => 'Les signataires signent dans l\'ordre : un signataire précédent n\'a pas encore signé.',
        'only_open_cancel' => 'Seule une demande en brouillon ou envoyée peut être annulée.',
        'expired' => 'La demande a expiré.',
        'not_open' => 'La demande n\'attend pas de signatures.',
        'token_invalid' => 'Le code de signature n\'est pas valide.',
        'provider_unknown' => 'Fournisseur de signature inconnu.',
        'link_invalid' => 'Ce lien de signature n\'est pas valide.',
    ],
];
