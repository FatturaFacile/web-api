<?php

declare(strict_types=1);

return [

    'locked' => "Un rapport d'intervention signé ou facturé ne peut plus être modifié.",
    'already_signed' => "Ce rapport d'intervention a déjà été signé.",
    'not_signable' => "Seuls les rapports d'intervention en brouillon ou terminés peuvent être signés.",
    'not_invoiceable' => "Seuls les rapports d'intervention terminés ou signés peuvent être facturés.",
    'no_lines' => "Un rapport d'intervention sans lignes ne peut pas être facturé.",
    'not_completable' => "Seuls les rapports d'intervention en brouillon peuvent être terminés.",
    'internal_not_invoiceable' => "Un ordre de travail interne n'a pas de client et ne peut pas être facturé.",
    'visit_requires_technician' => "Une visite nécessite un technicien : indiquez-en un ou affectez d'abord l'ordre de travail.",
    'part_unknown_product' => "Aucun produit de l'entreprise n'a ce numéro d'article.",
    'part_not_open' => 'Les pièces ne peuvent être consommées que sur un ordre de travail planifié, affecté ou en cours.',
    'part_quantity_positive' => 'La quantité doit être supérieure à zéro.',
    'billing_not_completed' => 'Seul un ordre de travail terminé peut être facturé.',
    'billing_not_billable' => "Cet ordre de travail n'est pas facturable.",
    'billing_already_invoiced' => 'Cet ordre de travail a déjà été facturé.',
    'billing_nothing_to_invoice' => "Il n'y a rien à facturer sur cet ordre de travail.",

    'messages' => [
        'created' => "Rapport d'intervention créé avec succès",
        'updated' => "Rapport d'intervention mis à jour avec succès",
        'retrieved' => "Rapport d'intervention récupéré avec succès",
        'completed' => "Rapport d'intervention terminé",
        'signed' => "Rapport d'intervention signé avec succès",
        'invoiced' => "Facture créée à partir du rapport d'intervention",
    ],

];
