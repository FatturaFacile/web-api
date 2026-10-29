<?php

declare(strict_types=1);

return [
    'messages' => [
        'instance_started' => 'Processus d\'approbation démarré avec succès',
        'decision_recorded' => 'Décision d\'approbation enregistrée avec succès',
        'step_approved' => 'Étape approuvée avec succès',
        'step_rejected' => 'Étape rejetée',
        'instance_completed' => 'Processus d\'approbation terminé avec succès',
        'instance_cancelled' => 'Processus d\'approbation annulé',
        'token_validated' => 'Jeton d\'approbation à distance validé',
    ],
    'errors' => [
        'unauthorized_approver' => 'L\'utilisateur n\'est pas habilité à approuver cette étape de workflow.',
        'invalid_state' => 'Le workflow n\'est pas dans un état valide pour la décision requise.',
    ],
];
