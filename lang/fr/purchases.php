<?php

declare(strict_types=1);

return [
    'order_locked' => 'Seules les commandes en brouillon ou envoyées peuvent être modifiées.',
    'order_not_confirmable' => 'Seules les commandes en brouillon peuvent être confirmées.',
    'order_not_receivable' => 'Les réceptions ne sont possibles que pour les commandes envoyées ou partiellement reçues.',
    'receipt_exceeds_order' => 'La quantité pour ":description" dépasse la quantité commandée restante.',
    'nothing_to_receive' => 'Plus rien à recevoir.',

    'invalid_fatturapa_title' => 'Fichier FatturaPA non valide',
    'unknown_recipient_title' => 'Destinataire inconnu',
    'invalid_fatturapa' => 'Le fichier n\'est pas un document FatturaPA valide.',
    'recipient_mismatch' => 'La facture est adressée à un autre numéro de TVA.',
    'unknown_recipient' => 'Aucune entité ne correspond au destinataire de la facture.',
    'credit_note_not_payable' => 'Les avoirs ne peuvent pas être payés directement.',
    'already_paid' => 'Cette facture fournisseur est déjà payée.',

    'messages' => [
        'order_created' => 'Commande fournisseur créée avec succès',
        'order_updated' => 'Commande fournisseur mise à jour avec succès',
        'order_retrieved' => 'Commande fournisseur récupérée avec succès',
        'order_confirmed' => 'Commande fournisseur confirmée',
        'goods_received' => 'Réception de marchandises enregistrée avec succès',
        'receipts' => 'Bons de réception récupérés avec succès',
        'receipt' => 'Bon de réception récupéré avec succès',
        'supplier_invoices' => 'Factures fournisseurs récupérées avec succès',
        'supplier_invoice' => 'Facture fournisseur récupérée avec succès',
        'invoices_received' => 'Factures fournisseurs reçues',
        'marked_paid' => 'Facture fournisseur marquée comme payée',
        'webhook_received' => 'Facture fournisseur traitée',
        'three_way_match_passed' => 'Rapprochement 3 voies validé avec succès',
        'three_way_match_discrepant' => 'Écart détecté lors du rapprochement 3 voies',
    ],
];
