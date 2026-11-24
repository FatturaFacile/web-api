<?php

declare(strict_types=1);

return [
    'errors' => [
        'user_limit' => 'L\'offre de l\'entreprise autorise au plus :max personnes actives, invitations comprises.',
        'role_limit' => 'L\'offre de l\'entreprise autorise au plus :max rôles personnalisés.',
        'role_in_use' => 'Des membres ont encore ce rôle : attribuez-leur d\'abord un autre rôle.',
        'delegator_not_member' => 'Le délégant n\'est pas un membre actif de l\'entreprise.',
        'delegate_not_member' => 'Le délégué n\'est pas un membre actif de l\'entreprise.',
        'delegate_is_delegator' => 'Une personne ne peut pas se déléguer à elle-même.',
        'delegate_for_others' => 'Seul un propriétaire ou un administrateur peut déléguer au nom d\'une autre personne.',
        'delegate_owner_reserved' => 'Seul un propriétaire peut déléguer l\'accès d\'un propriétaire.',
        'team_member_not_member' => 'Chaque membre de l\'équipe doit être membre de l\'entreprise.',
        'already_member' => 'Cette adresse appartient déjà à un membre de l\'entreprise.',
        'invitation_closed' => 'L\'invitation a déjà été acceptée ou révoquée.',
        'invitation_invalid' => 'Le lien d\'invitation n\'est pas valide ou a expiré.',
        'invitation_sign_in' => 'Connectez-vous avec le compte invité pour accepter cette invitation.',
        'import_no_header' => 'Le fichier doit avoir une ligne d\'en-tête avec la colonne email.',
        'import_too_many_rows' => 'Le fichier a trop de lignes (au plus :max).',
    ],
    'import' => [
        'already_member' => 'Déjà membre.',
        'already_invited' => 'Une invitation est déjà en attente.',
        'invalid_email' => 'L\'adresse e-mail n\'est pas valide.',
        'duplicate' => 'Répétée dans le fichier.',
        'unknown_role' => 'Rôle :role inconnu.',
        'unknown_team' => 'Équipe :team inconnue.',
        'failed' => 'La ligne n\'a pas pu être importée.',
    ],
    'mail' => [
        'invitation' => [
            'subject' => 'Invitation à :company - :app',
            'greeting' => 'Vous êtes invité',
            'body' => ':inviter vous a invité à rejoindre :company sur :app en tant que :role.',
            'action' => 'Accepter l\'invitation',
            'expires' => 'Le lien est valable jusqu\'au :date.',
        ],
    ],
];
