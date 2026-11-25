<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Données des notes de frais chargées.',
        'saved' => 'Enregistré.',
        'deleted' => 'Supprimé.',
        'submitted' => 'La note de frais a été soumise pour approbation.',
        'approved' => 'La note de frais a été approuvée.',
        'rejected' => 'La note de frais a été rejetée.',
        'exported' => 'Le paiement a été enregistré.',
        'scanned' => 'Justificatif lu.',
    ],
    'errors' => [
        'report_not_editable' => 'La note de frais n’est plus modifiable.',
        'report_empty' => 'La note de frais n’a ni ligne ni indemnité.',
        'blocking_violations' => ':count ligne(s) avec des violations bloquantes de la politique.',
        'missing_receipt' => 'Un justificatif est obligatoire.',
        'invalid_transition' => 'La note de frais ne peut pas passer de :from à :to.',
        'self_approval_forbidden' => 'Vous ne pouvez pas approuver votre propre note de frais.',
        'rejection_reason_required' => 'Un motif est requis pour rejeter une note de frais.',
        'no_reimbursable_amount' => 'Il n’y a rien à rembourser.',
        'employee_iban_missing' => 'L’employé n’a pas d’IBAN dans son profil de paie.',
        'debtor_account_missing' => 'Aucun compte bancaire actif de l’entreprise dans la devise de la note.',
        'card_transaction_mismatch' => 'La transaction de carte ne correspond pas à la ligne de frais (devise ou données).',
        'card_transaction_already_matched' => 'La transaction de carte ou la ligne de frais est déjà rapprochée.',
        'amount_exceeds_transaction' => 'Les montants rapprochés dépassent la transaction de carte (:amount).',
        'vehicle_rate_not_found' => 'Aucun barème au km pour le véhicule :vehicle à cette date.',
        'currency_rate_missing' => 'Aucun taux de change de :from vers :to ; saisissez le taux.',
        'receipt_not_found' => 'Justificatif introuvable.',
        'period_invalid' => 'La fin de la période précède son début.',
        'category_not_found' => 'Catégorie de frais introuvable.',
        'approved_amount_exceeds' => 'Le montant approuvé dépasse le montant remboursable (:max).',
    ],
];
