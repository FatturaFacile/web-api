<?php

declare(strict_types=1);

return [
    'errors' => [
        'forfettario_incompatible' => 'La retenue à la source, le paiement scindé et l\'autoliquidation ne sont pas autorisés sous le régime forfaitaire (RF19).',
        'enasarco_rate_not_configured' => 'Aucun taux ENASARCO configuré pour l\'année :year.',
        'split_payment_incompatible' => 'Le paiement scindé ne peut pas être combiné avec l\'autoliquidation.',
        'split_payment_requires_vat' => 'Le paiement scindé nécessite au moins une ligne avec TVA.',
        'invalid_natura' => 'Le code natura :natura n\'est pas valide.',
        'amount_out_of_range' => 'Un montant est trop élevé pour être calculé.',
        'invalid_quarter' => 'Le trimestre doit être compris entre 1 et 4.',
    ],

    'warnings' => [
        'zero_rate_without_natura' => 'La ligne :line a un taux de TVA de 0% sans code natura.',
        'split_payment_customer' => 'Le paiement scindé est généralement réservé aux administrations publiques et sociétés cotées ; vérifiez le client.',
    ],

    'messages' => [
        'preview' => 'Aperçu fiscal calculé avec succès',
        'stamp_duty_report' => 'Rapport de droit de timbre récupéré avec succès',
        'withholding_report' => 'Rapport de retenue à la source récupéré avec succès',
        'enasarco_status' => 'Statut ENASARCO récupéré avec succès',
    ],
];
