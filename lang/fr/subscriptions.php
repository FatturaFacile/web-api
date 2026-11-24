<?php

declare(strict_types=1);

return [

    'invalid_transition' => 'Un abonnement :status ne peut pas être :action.',

    'actions' => [
        'paused' => 'suspendu',
        'resumed' => 'repris',
        'cancelled' => 'résilié',
    ],

    'messages' => [
        'list' => 'Abonnements récupérés avec succès',
        'created' => 'Abonnement créé avec succès',
        'retrieved' => 'Abonnement récupéré avec succès',
        'updated' => 'Abonnement mis à jour avec succès',
        'deleted' => 'Abonnement supprimé avec succès',
        'runs' => 'Exécutions de facturation récupérées avec succès',
        'billed' => '{0} Aucune facture générée|{1} :count facture générée|[2,*] :count factures générées',
        'paused' => 'Abonnement suspendu avec succès',
        'resumed' => 'Abonnement repris avec succès',
        'cancelled' => 'Abonnement résilié avec succès',
    ],

];
