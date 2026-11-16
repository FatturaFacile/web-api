<?php

declare(strict_types=1);

return [
    'event' => [
        'not_published' => 'L’événement n’est pas ouvert aux inscriptions.',
        'full' => 'L’événement est complet.',
        'period' => 'La fin ne peut pas précéder le début.',
        'capacity_below_confirmed' => 'La capacité ne peut pas être inférieure aux inscriptions confirmées.',
        'not_draft' => 'Seul un événement en brouillon peut être publié.',
        'ticket_required' => 'Ajoutez au moins un type de billet actif avant de publier.',
        'cannot_cancel' => 'Seul un événement en brouillon ou publié peut être annulé.',
        'delete_draft_only' => 'Seul un événement en brouillon sans inscriptions peut être supprimé.',
        'code_taken' => 'Le code :code est déjà utilisé par un autre événement.',
    ],
    'ticket' => [
        'unavailable' => 'Le type de billet n’existe pas ou n’est pas en vente.',
        'has_registrations' => 'Le type de billet a des inscriptions : désactivez-le plutôt.',
    ],
    'registration' => [
        'already_cancelled' => 'L’inscription est déjà annulée.',
        'checked_in' => 'Une inscription avec enregistrement ne peut pas être annulée.',
        'not_confirmed' => 'Seule une inscription confirmée peut être payée.',
    ],
    'checkin' => [
        'unknown_token' => 'Aucune inscription ne correspond à ce code QR.',
        'not_confirmed' => 'Seule une inscription confirmée peut s’enregistrer.',
        'already' => 'Ce participant s’est déjà enregistré à :at.',
    ],
];
