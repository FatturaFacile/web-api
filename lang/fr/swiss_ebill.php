<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Enregistrements récupérés avec succès.',
        'loaded' => 'Enregistrement récupéré avec succès.',
        'saved' => 'Les paramètres eBill ont été enregistrés.',
        'connection_ok' => 'La passerelle eBill est joignable et a accepté les identifiants.',
        'connection_failed' => 'Le test de la passerelle eBill a échoué.',
        'secret_rotated' => 'Un nouveau secret de webhook a été émis. Copiez-le maintenant : il ne sera plus affiché.',
        'participant_found' => 'Le participant est inscrit dans l’annuaire eBill.',
        'participant_not_found' => 'Aucun participant ne correspond à cet identifiant dans l’annuaire eBill.',
        'customer_synced' => 'Le statut eBill du client a été vérifié.',
        'customer_linked' => 'Le client a été lié au participant eBill.',
        'bulk_verify_queued' => 'La vérification des clients se poursuit en arrière-plan.',
        'transmission_queued' => 'La facture a été mise en file pour l’envoi eBill.',
        'transmission_cancelled' => 'L’eBill a été rappelée.',
        'webhook_processed' => 'Les notifications ont été traitées.',
    ],

    'errors' => [
        'ebill_disabled' => 'eBill n’est pas activé pour cette entreprise.',
        'not_configured' => 'eBill n’est pas configuré pour cette entreprise.',
        'already_transmitted' => 'Cette facture a déjà une transmission eBill active.',
        'cannot_revise' => 'Seule une transmission refusée, échouée ou annulée peut être révisée.',
        'cannot_retry' => 'Seule une transmission échouée pour une raison technique peut être relancée.',
        'cannot_cancel' => 'Cette eBill ne peut plus être rappelée.',
        'gateway_error' => 'La passerelle eBill a refusé la demande.',
        'gateway_unavailable' => 'La passerelle eBill est injoignable pour le moment. Réessayez plus tard.',
        'payload_invalid' => 'L’eBill ne peut pas être construite : des données obligatoires manquent ou sont invalides.',
        'invalid_transition' => 'L’eBill ne peut pas passer à ce statut depuis son statut actuel.',
        'invalid_signature' => 'La signature du webhook n’est pas valide.',
        'unknown_webhook' => 'Point de terminaison de webhook inconnu.',
        'unknown_transaction' => 'Aucune eBill ne porte cet identifiant de transaction.',
        'payload_unreadable' => 'Le contenu du webhook est illisible.',
        'participant_not_found' => 'Le participant n’est pas dans l’annuaire eBill.',
        'invalid_lookup_value' => 'L’identifiant n’est pas valide pour ce type de recherche.',
        'payload_unavailable' => 'Aucune charge utile n’est conservée pour cette eBill.',
        'sandbox_not_allowed' => 'Le simulateur sandbox n’est pas disponible en production.',
    ],

    'notification' => [
        'rejected_subject' => 'eBill refusée par le client : facture :number',
        'rejected_line' => 'Le client :customer a refusé dans l’e-banking l’eBill de la facture :number.',
        'rejected_code' => 'Code de refus : :code',
        'rejected_reason' => 'Motif indiqué par le client : :reason',
        'rejected_action' => 'Ouvrez la facture, corrigez ce qui est nécessaire et utilisez « Réviser et renvoyer l’eBill ».',
    ],
];
