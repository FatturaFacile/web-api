<?php

declare(strict_types=1);

return [

    'errors' => [
        'unsupported_driver' => 'Le pilote e-commerce ":driver" n\'est pas pris en charge.',
        'missing_configuration' => 'Un paramètre obligatoire est manquant dans le canal : :field.',
        'http_error' => 'La boutique a répondu :status à :operation.',
        'invalid_response' => 'La boutique a renvoyé une réponse incompréhensible (:operation).',
        'invalid_url' => 'L\'URL doit être une adresse http ou https publique.',
        'credentials_required' => 'L\'identifiant ":field" est obligatoire pour le pilote :driver.',
        'channel_inactive' => 'Ce canal n\'est pas actif.',
        'sync_failed' => 'La synchronisation avec la boutique a échoué.',
        'unknown_channel' => 'Le canal n\'existe pas ou n\'est pas actif.',
        'invalid_signature' => 'La signature du webhook n\'est pas valide.',
        'webhook_expired' => 'L\'envoi du webhook est trop ancien pour être accepté.',
    ],

    'order' => [
        'note' => 'Commande e-commerce :number importée depuis :channel',
        'shipping' => 'Expédition : :method',
        'shipping_fallback' => 'Expédition',
        'line_fallback' => 'Article :id',
    ],

    'problem_titles' => [
        'sync' => 'Échec de la synchronisation de la boutique',
        'webhook' => 'Webhook rejeté',
    ],

    'messages' => [
        'channels' => 'Canaux e-commerce récupérés avec succès',
        'channel' => 'Canal e-commerce récupéré avec succès',
        'channel_created' => 'Canal e-commerce créé avec succès',
        'channel_updated' => 'Canal e-commerce mis à jour avec succès',
        'channel_deleted' => 'Canal e-commerce supprimé avec succès',
        'connection_ok' => 'La connexion à la boutique fonctionne.',
        'connection_failed' => 'La boutique n\'a pas accepté la connexion. Vérifiez l\'URL et les identifiants.',
        'sync_done' => 'Synchronisation terminée',
        'webhook_processed' => 'Webhook traité',
        'webhook_replayed' => 'Webhook déjà reçu : ignoré',
    ],

];
