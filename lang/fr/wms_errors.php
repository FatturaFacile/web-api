<?php

declare(strict_types=1);

return [
    'task' => [
        'not_editable' => 'Une tache au statut :status ne peut plus etre modifiee.',
        'field_required' => 'Une tache de type :type exige ce champ.',
        'bin_other_warehouse' => 'L\'emplacement n\'appartient pas a l\'entrepot de la tache.',
        'bin_locked' => 'L\'emplacement :bin est bloque : rien ne peut y etre deplace.',
        'same_bin' => 'L\'emplacement de destination doit etre different de l\'emplacement source.',
        'not_deletable' => 'Une tache au statut :status ne peut pas etre supprimee : annulez-la.',
        'invalid_transition' => 'La tache est au statut :status : cette etape n\'est pas possible.',
        'over_planned' => 'La quantite ne peut pas depasser les :planned prevus.',
        'use_short' => 'Moins que les :planned prevus ont ete traites : cloturez la tache comme partielle avec un motif.',
        'short_below_planned' => 'Une tache partielle doit etre inferieure aux :planned prevus : confirmez-la plutot.',
        'assigned_to_other' => 'La tache est affectee a un autre operateur.',
        'wave_not_released' => 'La vague de la tache n\'a pas encore ete liberee.',
        'not_a_member' => 'L\'utilisateur n\'est pas membre de cette entreprise.',
    ],
    'wave_rule' => [
        'bounds' => 'Le nombre minimal de taches ne peut pas depasser le maximum.',
    ],
    'slotting_rule' => [
        'bounds' => 'La vitesse minimale ne peut pas depasser la maximale.',
    ],
    'slotting' => [
        'reason' => 'Article de classe :class a :velocity prelevements par jour dans un emplacement :from : il doit aller dans un emplacement :to.',
        'stock_moved' => 'L\'article n\'est plus en stock dans l\'emplacement source : generez de nouveau les recommandations.',
        'not_pending' => 'La recommandation est deja au statut :status.',
    ],
    'cross_dock' => [
        'received_over_inbound' => 'La quantite recue ne peut pas depasser la quantite entrante de :inbound.',
        'received_below_outbound' => 'La quantite recue ne peut pas etre inferieure aux :outbound deja associes a la sortie.',
        'outbound_over_available' => 'La quantite sortante ne peut pas depasser les :available disponibles.',
        'nothing_matched' => 'Rien n\'est encore associe a la sortie.',
        'invalid_transition' => 'L\'ordre de cross-docking est au statut :status : cette etape n\'est pas possible.',
        'door_other_warehouse' => 'Le quai n\'appartient pas a l\'entrepot de l\'ordre.',
        'door_direction' => 'Le quai ne dessert pas le trafic :direction.',
        'duplicate_item' => 'L\'article apparait sur plusieurs lignes.',
        'outbound_over_inbound' => 'La quantite sortante ne peut pas depasser la quantite entrante de :inbound.',
        'line_not_found' => 'La ligne n\'appartient pas a cet ordre de cross-docking.',
    ],
    'dock' => [
        'direction_in_use' => 'Le quai a des rendez-vous actifs d\'une autre direction : la direction ne peut pas changer.',
        'door_in_use' => 'Le quai a encore des rendez-vous actifs ou des ordres de cross-docking ouverts.',
        'not_editable' => 'Un rendez-vous au statut :status ne peut plus etre modifie.',
        'door_inactive' => 'Le quai :door n\'est pas actif.',
        'door_direction' => 'Le quai :door ne dessert pas le trafic :direction.',
        'ends_before_start' => 'Le rendez-vous doit se terminer apres son debut.',
        'conflict' => 'Le quai :door est deja reserve de :from a :to.',
        'cross_dock_other_warehouse' => 'L\'ordre de cross-docking appartient a un autre entrepot.',
        'not_deletable' => 'Un rendez-vous au statut :status ne peut pas etre supprime.',
        'invalid_transition' => 'Le rendez-vous est au statut :status : cette etape n\'est pas possible.',
        'no_show_too_early' => 'Le rendez-vous n\'a pas encore commence : il ne peut pas etre une absence.',
    ],
    'wave' => [
        'rule_inactive' => 'La regle de vague n\'est pas active.',
        'not_enough_tasks' => 'Seules :found taches de prelevement ouvertes correspondent a la regle, le minimum est :minimum.',
        'tasks_open' => ':count taches de la vague sont encore ouvertes.',
        'work_started' => 'Le travail sur la vague a deja commence : elle ne peut pas etre annulee.',
        'invalid_transition' => 'La vague est au statut :status : cette etape n\'est pas possible.',
    ],
];
