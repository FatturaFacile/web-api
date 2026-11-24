<?php

declare(strict_types=1);

return [
    'task' => [
        'not_editable' => 'Une tâche au statut :status ne peut plus être modifiée.',
        'field_required' => 'Une tâche de type :type exige ce champ.',
        'bin_other_warehouse' => 'L\'emplacement n\'appartient pas à l\'entrepôt de la tâche.',
        'bin_locked' => 'L\'emplacement :bin est bloqué : rien ne peut y être déplacé.',
        'same_bin' => 'L\'emplacement de destination doit être différent de l\'emplacement source.',
        'not_deletable' => 'Une tâche au statut :status ne peut pas être supprimée : annulez-la.',
        'invalid_transition' => 'La tâche est au statut :status : cette étape n\'est pas possible.',
        'over_planned' => 'La quantité ne peut pas dépasser les :planned prévus.',
        'use_short' => 'Moins que les :planned prévus ont été traités : clôturez la tâche comme partielle avec un motif.',
        'short_below_planned' => 'Une tâche partielle doit être inférieure aux :planned prévus : confirmez-la plutôt.',
        'assigned_to_other' => 'La tâche est affectée à un autre opérateur.',
        'wave_not_released' => 'La vague de la tâche n\'a pas encore été libérée.',
        'stock_rejected' => 'Le mouvement de stock a été refusé : :reason',
        'not_a_member' => 'L\'utilisateur n\'est pas membre de cette entreprise.',
    ],
    'wave_rule' => [
        'bounds' => 'Le nombre minimal de tâches ne peut pas dépasser le maximum.',
    ],
    'slotting_rule' => [
        'bounds' => 'La vitesse minimale ne peut pas dépasser la maximale.',
    ],
    'slotting' => [
        'reason' => 'Article de classe :class à :velocity prélèvements par jour dans un emplacement :from : il doit aller dans un emplacement :to.',
        'stock_moved' => 'L\'article n\'est plus en stock dans l\'emplacement source : générez de nouveau les recommandations.',
        'not_pending' => 'La recommandation est déjà au statut :status.',
    ],
    'cross_dock' => [
        'received_over_inbound' => 'La quantité reçue ne peut pas dépasser la quantité entrante de :inbound.',
        'received_below_outbound' => 'La quantité reçue ne peut pas être inférieure aux :outbound déjà associés à la sortie.',
        'outbound_over_available' => 'La quantité sortante ne peut pas dépasser les :available disponibles.',
        'nothing_matched' => 'Rien n\'est encore associé à la sortie.',
        'invalid_transition' => 'L\'ordre de cross-docking est au statut :status : cette étape n\'est pas possible.',
        'door_other_warehouse' => 'Le quai n\'appartient pas à l\'entrepôt de l\'ordre.',
        'door_direction' => 'Le quai ne dessert pas le trafic :direction.',
        'duplicate_item' => 'L\'article apparaît sur plusieurs lignes.',
        'outbound_over_inbound' => 'La quantité sortante ne peut pas dépasser la quantité entrante de :inbound.',
        'line_not_found' => 'La ligne n\'appartient pas à cet ordre de cross-docking.',
    ],
    'dock' => [
        'direction_in_use' => 'Le quai a des rendez-vous actifs d\'une autre direction : la direction ne peut pas changer.',
        'door_in_use' => 'Le quai a encore des rendez-vous actifs ou des ordres de cross-docking ouverts.',
        'not_editable' => 'Un rendez-vous au statut :status ne peut plus être modifié.',
        'door_inactive' => 'Le quai :door n\'est pas actif.',
        'door_direction' => 'Le quai :door ne dessert pas le trafic :direction.',
        'ends_before_start' => 'Le rendez-vous doit se terminer après son début.',
        'conflict' => 'Le quai :door est déjà réservé de :from à :to.',
        'cross_dock_other_warehouse' => 'L\'ordre de cross-docking appartient à un autre entrepôt.',
        'not_deletable' => 'Un rendez-vous au statut :status ne peut pas être supprimé.',
        'invalid_transition' => 'Le rendez-vous est au statut :status : cette étape n\'est pas possible.',
        'no_show_too_early' => 'Le rendez-vous n\'a pas encore commencé : il ne peut pas être une absence.',
    ],
    'wave' => [
        'rule_inactive' => 'La règle de vague n\'est pas active.',
        'not_enough_tasks' => 'Seules :found tâches de prélèvement ouvertes correspondent à la règle, le minimum est :minimum.',
        'tasks_open' => ':count tâches de la vague sont encore ouvertes.',
        'work_started' => 'Le travail sur la vague a déjà commencé : elle ne peut pas être annulée.',
        'invalid_transition' => 'La vague est au statut :status : cette étape n\'est pas possible.',
    ],
];
