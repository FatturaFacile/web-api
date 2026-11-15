<?php

declare(strict_types=1);

return [
    'quantity_positive' => 'La quantité doit être supérieure à zéro.',
    'transition' => 'Le statut ne peut pas passer de :from à :to.',
    'reason' => [
        'duplicate' => 'Le code motif :code existe déjà.',
        'unknown' => 'Le code motif :code n\'existe pas ou n\'est pas actif.',
    ],
    'stock' => [
        'warehouse_required' => 'Un entrepôt est nécessaire pour enregistrer le mouvement de stock et l\'entreprise n\'en a pas.',
    ],
    'rma' => [
        'locked' => 'Un retour ne peut être modifié que tant qu\'il est demandé.',
        'customer_unknown' => 'Le client n\'existe pas dans l\'entreprise.',
        'source_required' => 'Une facture ou une commande de vente du client est requise.',
        'invoice_not_returnable' => 'La facture n\'existe pas, n\'est pas une facture du client ou ne peut pas être retournée (avoirs et proformas).',
        'order_not_returnable' => 'La commande de vente n\'existe pas ou n\'est pas une commande du client.',
        'window_expired' => 'Le délai de retour de :days jours après la date du document est dépassé.',
        'override_not_allowed' => 'Seul un approbateur peut accepter un retour après le délai de retour.',
        'line_source_required' => 'Chaque ligne doit indiquer la ligne de facture ou de commande qu\'elle retourne.',
        'line_not_of_source' => 'La ligne n\'appartient pas au document d\'origine du retour.',
        'quantity_exceeds' => 'Il ne reste que :available à retourner de « :line ».',
        'line_not_of_return' => 'Une ligne n\'appartient pas à ce retour.',
        'received_exceeds' => 'La quantité reçue de « :line » est négative ou dépasse la quantité autorisée.',
        'nothing_received' => 'Au moins une ligne doit être reçue.',
        'disposition_required' => 'La ligne « :line » nécessite une destination.',
        'inspected_exceeds' => 'La quantité inspectée de « :line » doit être positive et ne pas dépasser la quantité reçue.',
        'supplier_required' => 'La ligne « :line » est retournée à un fournisseur : choisissez un fournisseur existant.',
        'credit_note_reference_required' => 'La référence du brouillon d\'avoir est obligatoire.',
        'stock_note' => 'Retour client :number',
        'replacement_note' => 'Remplacement pour le retour :number',
    ],
    'warranty' => [
        'void_locked' => 'Une garantie annulée ne peut pas être modifiée.',
        'product_unknown' => 'Le produit n\'existe pas dans l\'entreprise.',
        'period_invalid' => 'La garantie ne peut pas se terminer avant de commencer.',
        'identifier_required' => 'Un numéro de série ou un numéro de lot est requis.',
        'serial_taken' => 'Le numéro de série :serial a déjà une garantie active pour ce produit.',
    ],
    'claim' => [
        'warranty_unknown' => 'La garantie n\'existe pas dans l\'entreprise.',
        'rma_mismatch' => 'Le retour n\'existe pas ou appartient à un autre client que la garantie.',
        'not_valid' => 'La garantie ne couvrait pas la date de la réclamation (:reason) : seul un approbateur peut l\'accepter.',
        'status_expected' => 'La réclamation est :status : attendu :expected.',
    ],
    'rtv' => [
        'locked' => 'Seul un retour fournisseur en brouillon peut changer de fournisseur, de lignes et d\'avoir attendu.',
        'final' => 'Un retour fournisseur clos ou annulé ne peut pas être modifié.',
        'no_lines' => 'Un retour fournisseur nécessite au moins une ligne.',
        'supplier_unknown' => 'Le fournisseur n\'existe pas dans l\'entreprise.',
        'product_unknown' => 'Le produit n\'existe pas dans l\'entreprise.',
        'receipt_line_mismatch' => 'La ligne de réception n\'existe pas ou concerne un autre produit.',
        'quantity_exceeds' => 'Il ne reste que :available à retourner de « :line ».',
        'stock_note' => 'Retour fournisseur :number',
    ],
];
