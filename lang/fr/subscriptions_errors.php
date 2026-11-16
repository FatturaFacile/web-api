<?php

declare(strict_types=1);

return [
    'dunning' => [
        'invoice_not_unpaid' => 'La facture n\'est pas une facture émise et impayée de cet abonnement.',
    ],
    'subscription' => [
        'start_date_locked' => 'La date de début d\'un abonnement déjà facturé ne peut pas changer.',
        'no_quantity_item' => 'L\'abonnement n\'a aucune ligne de plan dont la quantité puisse changer.',
        'already_closed' => 'L\'abonnement est déjà clôturé.',
        'pending_approval' => 'L\'abonnement est en attente d\'approbation et ne peut pas changer.',
    ],
    'plan' => [
        'not_active' => 'Le plan n\'est pas un plan actif de cette entreprise.',
        'currency_interval_mismatch' => 'Le nouveau plan doit avoir la devise et la périodicité de facturation de l\'abonnement.',
        'use_downgrade' => 'Le nouveau plan coûte moins cher : utilisez un downgrade.',
        'use_upgrade' => 'Le nouveau plan coûte plus cher : utilisez un upgrade.',
    ],
    'usage' => [
        'item_not_metered' => 'La ligne n\'est pas une ligne à la consommation d\'un abonnement de cette entreprise.',
        'subscription_closed' => 'L\'abonnement de la ligne est clôturé.',
        'metric_mismatch' => 'L\'indicateur ne correspond pas à l\'indicateur de la ligne.',
    ],
];
