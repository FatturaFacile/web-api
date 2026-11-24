<?php

declare(strict_types=1);

return [
    'messages' => [
        'challenge_required' => 'Saisissez le code de votre application d\'authentification pour terminer la connexion.',
        'setup_started' => 'Scannez le code QR avec votre application d\'authentification et confirmez avec le premier code.',
        'enabled' => 'Authentification à deux facteurs activée. Conservez les codes de récupération en lieu sûr.',
        'disabled' => 'Authentification à deux facteurs désactivée.',
        'recovery_codes_regenerated' => 'Nouveaux codes de récupération générés. Les précédents ne sont plus valables.',
        'policy_updated' => 'Règle d\'authentification à deux facteurs mise à jour.',
        'member_reset' => 'L\'authentification à deux facteurs de l\'utilisateur a été réinitialisée.',
    ],
    'errors' => [
        'challenge_invalid_title' => 'Connexion expirée',
        'challenge_invalid_detail' => 'La vérification a expiré ou n\'est pas valable. Reconnectez-vous avec e-mail et mot de passe.',
        'code_invalid_title' => 'Code non valable',
        'code_invalid_detail' => 'Le code n\'est pas valable ou a déjà été utilisé.',
        'password_invalid' => 'Le mot de passe n\'est pas correct.',
        'already_enabled_title' => 'Déjà activée',
        'already_enabled_detail' => 'L\'authentification à deux facteurs est déjà active. Désactivez-la avant de la configurer à nouveau.',
        'no_pending_setup_title' => 'Aucune configuration en cours',
        'no_pending_setup_detail' => 'Démarrez d\'abord la configuration de l\'authentification à deux facteurs.',
        'not_enabled_title' => 'Non activée',
        'not_enabled_detail' => 'L\'authentification à deux facteurs n\'est pas active.',
        'setup_required_title' => 'Authentification à deux facteurs obligatoire',
        'setup_required_detail' => 'Votre entreprise exige l\'authentification à deux facteurs. Configurez-la pour continuer.',
        'admin_setup_required_title' => 'Configurez d\'abord vos propres deux facteurs',
        'admin_setup_required_detail' => 'Activez l\'authentification à deux facteurs sur votre compte avant de la rendre obligatoire.',
        'member_not_found_title' => 'Utilisateur introuvable',
        'member_not_found_detail' => 'L\'utilisateur ne fait pas partie de cette entreprise.',
        'reset_not_allowed_title' => 'Réinitialisation non autorisée',
        'reset_not_allowed_detail' => 'Vous ne pouvez pas réinitialiser votre propre compte, un administrateur de la plateforme ou un utilisateur qui appartient aussi à d\'autres entreprises.',
    ],
];
