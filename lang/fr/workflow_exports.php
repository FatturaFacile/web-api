<?php

declare(strict_types=1);

return [
    'evidence' => [
        'title' => 'Preuve d\'approbation',
        'document' => 'Document', 'requester' => 'Demandeur', 'amount' => 'Montant (monnaie fonctionnelle)', 'workflow' => 'Flux',
        'status' => 'Statut', 'started' => 'Démarré', 'completed' => 'Terminé', 'steps' => 'Étapes d\'approbation',
        'date' => 'Date', 'time' => 'Heure', 'identity' => 'Identité', 'node' => 'Étape', 'outcome' => 'Résultat', 'channel' => 'Canal', 'reason' => 'Motif',
        'chain_intact' => 'Piste d\'audit : :count entrées, chaîne de hachage intacte.',
        'chain_broken' => 'Piste d\'audit : :count entrées, la chaîne de hachage n\'est PAS intacte. Ne vous fiez pas à cette preuve.',
        'neutrality' => 'Preuve de contrôle interne (CO art. 728a/728b, D.Lgs. 231/2001, L. 262/2005). Ce document consigne qui a décidé quoi, quand et par quel canal ; il ne calcule aucun impôt et n\'a aucun effet fiscal.',
    ],
    'status' => [
        'pending' => 'En attente', 'approved' => 'Approuvé', 'rejected' => 'Refusé', 'skipped' => 'Ignoré', 'escalated' => 'Escaladé',
        'expired' => 'Expiré', 'cancelled' => 'Annulé', 'changes_requested' => 'Modifications demandées', 'reassigned' => 'Réassigné',
        'timed_out' => 'Délai dépassé', 'delegated' => 'Délégué',
    ],
    'event' => [
        'instance_started' => 'Approbation démarrée', 'step_reassigned' => 'Étape réassignée', 'step_reassigned_removed_approver' => 'Étape transmise (approbateur parti)',
        'instance_withdrawn' => 'Retirée par le demandeur', 'instance_cancelled' => 'Annulée', 'instance_cancelled_by_admin' => 'Annulée par un administrateur',
        'instance_blocked' => 'Bloquée : aucun approbateur', 'changes_requested' => 'Modifications demandées', 'approval_invalidated' => 'Approbation invalidée par une modification', 'instance_expired' => 'Expirée',
    ],
    'instance' => [
        'pending' => 'En attente', 'in_progress' => 'En cours', 'approved' => 'Approuvée', 'rejected' => 'Refusée', 'cancelled' => 'Annulée',
        'withdrawn' => 'Retirée', 'blocked' => 'Bloquée', 'expired' => 'Expirée',
    ],
    'sla' => [
        'title' => 'Rapport SLA des approbations', 'by_definition' => 'Par flux', 'by_node' => 'Par étape', 'by_approver' => 'Par approbateur',
        'definition' => 'Flux', 'node' => 'Étape', 'approver' => 'Approbateur', 'steps' => 'Étapes', 'on_time' => 'Dans les délais', 'late' => 'En retard',
        'timed_out' => 'Délai dépassé', 'overdue' => 'En retard ouverts', 'open' => 'Ouverts', 'escalated' => 'Escaladés', 'avg_hours' => 'Heures moyennes pour décider',
    ],
];
