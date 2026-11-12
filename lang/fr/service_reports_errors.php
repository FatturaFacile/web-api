<?php

declare(strict_types=1);

return [
    'consolidated.min_two' => 'Au moins deux rapports d\'intervention sont nécessaires pour générer une facture consolidée.',
    'consolidated.not_found' => 'Un ou plusieurs rapports d\'intervention sont introuvables.',
    'consolidated.same_customer' => 'Tous les rapports d\'intervention consolidés doivent appartenir au même client/contact.',
    'consolidated.cancelled' => 'Le rapport d\'intervention :number est annulé et ne peut pas être facturé.',
    'consolidated.not_signed' => 'Le rapport d\'intervention :number n\'est pas signé par le client.',
    'consolidated.already_invoiced' => 'Le rapport d\'intervention :number a déjà été facturé.',
    'consolidated.no_billable_lines' => 'Aucun des rapports d\'intervention sélectionnés ne contient de lignes facturables.',
    'invoice.cancelled' => 'Les rapports d\'intervention annulés ne peuvent pas être facturés.',
    'invoice.not_signed' => 'Le rapport d\'intervention doit être signé par le client avant de générer une facture.',
    'invoice.already_invoiced' => 'Ce rapport d\'intervention a déjà été facturé.',
    'invoice.no_billable_lines' => 'Le rapport d\'intervention ne contient aucune ligne facturable.',
    'save.signed_locked' => 'Les rapports d\'intervention signés ne peuvent pas être modifiés.',
    'save.invoiced_locked' => 'Les rapports d\'intervention facturés ne peuvent pas être modifiés.',
    'sign.already_signed' => 'Ce rapport d\'intervention est déjà signé.',
    'sign.invalid_signature' => 'Le contenu de l\'image PNG de la signature n\'est pas valide.',
];
