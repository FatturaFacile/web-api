<?php

declare(strict_types=1);

return [
    'channel' => [
        'not_configured' => 'Configurez le canal avant de le tester.',
    ],
    'conversation' => [
        'closed' => 'La conversation est fermée.',
    ],
    'campaign' => [
        'not_cancellable' => 'Seule une campagne en brouillon ou planifiée peut être annulée.',
        'segment_not_found' => 'Sélectionnez un segment existant dans cette société.',
        'locked' => 'Une campagne envoyée ou en cours ne peut plus être modifiée.',
        'segment_required' => "Choisissez d'abord le segment de l'audience.",
    ],
    'template' => [
        'not_found' => 'Aucun modèle actif pour ce code et ce canal.',
        'missing_variables' => 'Variables manquantes : :variables.',
    ],
    'webhook' => [
        'unknown_channel' => 'Aucun canal e-mail entrant trouvé.',
        'invalid_signature' => 'Signature invalide ou expirée.',
    ],
];
