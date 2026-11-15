<?php

declare(strict_types=1);

return [
    'task' => [
        'not_editable' => 'Un task in stato :status non può più essere modificato.',
        'field_required' => 'Un task di tipo :type richiede questo campo.',
        'bin_other_warehouse' => 'L\'ubicazione non appartiene al magazzino del task.',
        'bin_locked' => 'L\'ubicazione :bin è bloccata: non si può spostare merce al suo interno.',
        'same_bin' => 'L\'ubicazione di destinazione deve essere diversa da quella di origine.',
        'not_deletable' => 'Un task in stato :status non può essere eliminato: annullalo.',
        'invalid_transition' => 'Il task è in stato :status: questo passaggio non è possibile.',
        'over_planned' => 'La quantità non può superare quella pianificata di :planned.',
        'use_short' => 'È stato fatto meno dei :planned pianificati: chiudi il task come parziale indicando un motivo.',
        'short_below_planned' => 'Un task parziale deve essere inferiore ai :planned pianificati: confermalo.',
        'assigned_to_other' => 'Il task è assegnato a un altro operatore.',
        'wave_not_released' => 'L\'ondata del task non è ancora stata rilasciata.',
        'stock_rejected' => 'Il movimento di magazzino è stato rifiutato: :reason',
        'not_a_member' => 'L\'utente non fa parte di questa azienda.',
    ],
    'wave_rule' => [
        'bounds' => 'Il numero minimo di task non può superare il massimo.',
    ],
    'slotting_rule' => [
        'bounds' => 'La velocità minima non può superare la massima.',
    ],
    'slotting' => [
        'reason' => 'Articolo di classe :class con :velocity prelievi al giorno in ubicazione :from: va in ubicazione :to.',
        'stock_moved' => 'L\'articolo non è più a stock nell\'ubicazione di origine: genera di nuovo le raccomandazioni.',
        'not_pending' => 'La raccomandazione è già in stato :status.',
    ],
    'cross_dock' => [
        'received_over_inbound' => 'La quantità ricevuta non può superare la quantità in ingresso di :inbound.',
        'received_below_outbound' => 'La quantità ricevuta non può essere inferiore ai :outbound già abbinati all\'uscita.',
        'outbound_over_available' => 'La quantità in uscita non può superare la disponibile di :available.',
        'nothing_matched' => 'Nessuna quantità è ancora abbinata all\'uscita.',
        'invalid_transition' => 'L\'ordine di cross-docking è in stato :status: questo passaggio non è possibile.',
        'door_other_warehouse' => 'La baia non appartiene al magazzino dell\'ordine.',
        'door_direction' => 'La baia non serve il traffico :direction.',
        'duplicate_item' => 'L\'articolo compare su più righe.',
        'outbound_over_inbound' => 'La quantità in uscita non può superare la quantità in ingresso di :inbound.',
        'line_not_found' => 'La riga non appartiene a questo ordine di cross-docking.',
    ],
    'dock' => [
        'direction_in_use' => 'La baia ha appuntamenti attivi di altra direzione: la direzione non può cambiare.',
        'door_in_use' => 'La baia ha ancora appuntamenti attivi o ordini di cross-docking aperti.',
        'not_editable' => 'Un appuntamento in stato :status non può più essere modificato.',
        'door_inactive' => 'La baia :door non è attiva.',
        'door_direction' => 'La baia :door non serve il traffico :direction.',
        'ends_before_start' => 'L\'appuntamento deve finire dopo il suo inizio.',
        'conflict' => 'La baia :door è già prenotata dalle :from alle :to.',
        'cross_dock_other_warehouse' => 'L\'ordine di cross-docking appartiene a un altro magazzino.',
        'not_deletable' => 'Un appuntamento in stato :status non può essere eliminato.',
        'invalid_transition' => 'L\'appuntamento è in stato :status: questo passaggio non è possibile.',
        'no_show_too_early' => 'L\'appuntamento non è ancora iniziato: non può essere un mancato arrivo.',
    ],
    'wave' => [
        'rule_inactive' => 'La regola di ondata non è attiva.',
        'not_enough_tasks' => 'Solo :found task di prelievo aperti rispettano la regola, il minimo è :minimum.',
        'tasks_open' => ':count task dell\'ondata sono ancora aperti.',
        'work_started' => 'Il lavoro sull\'ondata è già iniziato: non si può annullare.',
        'invalid_transition' => 'L\'ondata è in stato :status: questo passaggio non è possibile.',
    ],
];
