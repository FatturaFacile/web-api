<?php

declare(strict_types=1);

return [
    'request' => ['title' => 'Approbation demandée', 'body' => ':type :label attend votre décision. Échéance : :due. Ouvrez le lien pour l\'examiner.'],
    'reminder' => ['title' => 'Rappel d\'approbation', 'body' => ':type :label attend toujours votre décision (échéance :due).'],
    'escalation' => ['title' => 'Approbation escaladée vers vous', 'body' => ':type :label n\'a pas été décidée à temps et demande maintenant votre décision.'],
    'approved' => ['title' => 'Demande approuvée', 'body' => 'Votre demande (:type :label) a été approuvée.'],
    'rejected' => ['title' => 'Demande refusée', 'body' => 'Votre demande (:type :label) a été refusée. Ouvrez le lien pour voir le motif.'],
    'changes_requested' => ['title' => 'Modifications demandées', 'body' => 'Un approbateur a demandé des modifications à votre demande (:type :label). Ouvrez le lien pour voir le motif.'],
    'expired' => ['title' => 'Approbation expirée', 'body' => 'Personne n\'a décidé à temps sur votre demande (:type :label). Elle a été clôturée.'],
    'withdrawn' => ['title' => 'Demande retirée', 'body' => 'Le demandeur a retiré la demande (:type :label). Il n\'y a plus rien à décider.'],
    'blocked' => ['title' => 'Approbation bloquée', 'body' => 'L\'approbation (:type :label) ne peut pas continuer car aucun approbateur n\'est trouvé. Désignez un approbateur ou annulez-la.'],
    'delegation' => ['title' => 'Délégation activée', 'body' => 'Vous remplacez un collègue du :from au :to. Ses approbations apparaissent dans votre boîte.'],
    'subject' => [
        'sales_quote' => 'Le devis',
        'purchase_request' => 'La demande d\'achat',
        'purchase_order' => 'La commande fournisseur',
        'supplier_invoice' => 'La facture fournisseur',
        'expense_report' => 'La note de frais',
        'leave_request' => 'La demande d\'absence',
        'payment_batch' => 'Le lot de paiement',
        'supplier_master' => 'La fiche fournisseur',
        'dms_document' => 'Le document',
        'document' => 'Le document',
    ],
];
