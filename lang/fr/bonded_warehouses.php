<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Liste récupérée avec succès.',
        'loaded' => 'Données récupérées avec succès.',
        'authorization_saved' => 'L’autorisation d’entrepôt a été enregistrée.',
        'authorization_deleted' => 'L’autorisation d’entrepôt a été supprimée.',
        'mrn_registered' => 'L’entrée en entrepôt sous MRN a été enregistrée et la garantie CGU a été engagée.',
        'discharge_simulated' => 'Simulation de l’apurement calculée : rien n’a été enregistré.',
        'discharge_executed' => 'L’apurement de l’entrepôt a été enregistré et la part de garantie a été libérée.',
        'vat_inbound_registered' => 'L’entrée a été enregistrée dans le registre de l’entrepôt IVA.',
        'extraction_previewed' => 'Aperçu de l’extraction calculé : rien n’a été enregistré.',
        'extraction_emitted' => 'L’extraction a été enregistrée et l’autofacture électronique a été créée.',
        'reconciliation_done' => 'Le rapprochement AEO a été exécuté.',
    ],

    'errors' => [
        'record_immutable' => 'Cet enregistrement fait partie de la piste d’audit et ne peut être ni modifié ni supprimé.',
        'authorization_not_found' => 'Autorisation d’entrepôt introuvable.',
        'authorization_inactive' => 'L’autorisation :code n’est pas active ou n’est pas valable à la date de l’opération.',
        'regime_not_customs_warehouse' => 'Cette autorisation est un entrepôt IVA : utilisez le registre de l’art. 50-bis.',
        'regime_not_vat_depot' => 'Cette autorisation n’est pas un entrepôt IVA de l’art. 50-bis.',
        'declaration_type_not_allowed' => 'La déclaration :declaration n’est pas admise pour le régime :regime.',
        'invalid_mrn' => 'Le MRN doit comporter 18 caractères : année (2 chiffres), pays (2 lettres) et 14 caractères alphanumériques.',
        'invalid_discharge_mrn' => 'Indiquez le MRN valide de la déclaration d’apurement.',
        'invalid_taric' => 'Le code TARIC doit comporter 8 ou 10 chiffres.',
        'mrn_already_registered' => 'Le MRN :mrn article :item est déjà enregistré.',
        'cgu_ceiling_exceeded' => 'Le plafond de la garantie CGU serait dépassé : disponible :available :currency, demandé :requested :currency.',
        'cgu_release_exceeds_utilized' => 'La libération dépasse la garantie utilisée.',
        'ceiling_below_utilized' => 'Le plafond ne peut pas être inférieur à la garantie déjà utilisée (:utilized).',
        'authorization_in_use' => 'L’autorisation a des lots ou une garantie engagée et ne peut pas être supprimée.',
        'quantity_must_be_positive' => 'La quantité doit être supérieure à zéro.',
        'insufficient_remaining_quantity' => 'La quantité restante en entrepôt est insuffisante : disponible :available, demandée :requested.',
        'insufficient_remaining_weight' => 'Le poids net restant est insuffisant pour le MRN :mrn.',
        'lot_not_found' => 'Lot MRN introuvable.',
        'discharge_before_entry' => 'La date d’apurement précède l’entrée du MRN :mrn.',
        'usual_handling_code_required' => 'Indiquez la manipulation usuelle (annexe 71-03 AD CDU).',
        'fifo_needs_product_and_quantity' => 'Un apurement FIFO exige un produit et une quantité.',
        'movement_not_inbound' => 'Le mouvement n’est pas une entrée de l’entrepôt IVA.',
        'movement_not_extraction' => 'Le mouvement n’est pas une extraction de l’entrepôt IVA.',
        'negative_value' => 'Les valeurs ne peuvent pas être négatives.',
        'f24_required' => 'Une extraction TD23 exige la référence et la date du paiement F24.',
        'f24_not_allowed' => 'Le paiement F24 ne concerne que les extractions TD23.',
        'insufficient_depot_stock' => 'Le stock de l’entrepôt IVA est insuffisant : disponible :available, demandé :requested.',
        'self_contact_missing' => 'Le contact de votre propre entreprise (même numéro de TVA) est absent : l’autofacture ne peut pas être émise.',
        'unknown_dataset' => 'Export non disponible.',
    ],

    'invoice' => [
        'goods_line' => 'Extraction de l’entrepôt IVA, lot :lot, quantité :quantity',
        'services_line' => 'Services de stockage et de transformation rendus dans l’entrepôt IVA',
        'note' => 'Extraction de l’entrepôt IVA (art. 50-bis DL 331/1993), base imposable :base, document :type.',
    ],
];
