<?php

declare(strict_types=1);

return [
    'task' => [
        'not_editable' => 'Un task in stato :status non puo piu essere modificato.',
        'field_required' => 'Un task di tipo :type richiede questo campo.',
        'bin_other_warehouse' => 'L\'ubicazione non appartiene al magazzino del task.',
        'bin_locked' => 'L\'ubicazione :bin e bloccata: non si puo spostare merce al suo interno.',
        'same_bin' => 'L\'ubicazione di destinazione deve essere diversa da quella di origine.',
        'not_deletable' => 'Un task in stato :status non puo essere eliminato: annullalo.',
        'invalid_transition' => 'Il task e in stato :status: questo passaggio non e possibile.',
        'over_planned' => 'La quantita non puo superare quella pianificata di :planned.',
        'use_short' => 'E stato fatto meno dei :planned pianificati: chiudi il task come parziale indicando un motivo.',
        'short_below_planned' => 'Un task parziale deve essere inferiore ai :planned pianificati: confermalo.',
        'assigned_to_other' => 'Il task e assegnato a un altro operatore.',
        'wave_not_released' => 'L\'ondata del task non e ancora stata rilasciata.',
        'not_a_member' => 'L\'utente non fa parte di questa azienda.',
    ],
    'wave_rule' => [
        'bounds' => 'Il numero minimo di task non puo superare il massimo.',
    ],
    'slotting_rule' => [
        'bounds' => 'La velocita minima non puo superare la massima.',
    ],
    'slotting' => [
        'reason' => 'Articolo di classe :class con :velocity prelievi al giorno in ubicazione :from: va in ubicazione :to.',
        'stock_moved' => 'L\'articolo non e piu a stock nell\'ubicazione di origine: genera di nuovo le raccomandazioni.',
        'not_pending' => 'La raccomandazione e gia in stato :status.',
    ],
    'cross_dock' => [
        'received_over_inbound' => 'La quantita ricevuta non puo superare la quantita in ingresso di :inbound.',
        'received_below_outbound' => 'La quantita ricevuta non puo essere inferiore ai :outbound gia abbinati all\'uscita.',
        'outbound_over_available' => 'La quantita in uscita non puo superare la disponibile di :available.',
        'nothing_matched' => 'Nessuna quantita e ancora abbinata all\'uscita.',
        'invalid_transition' => 'L\'ordine di cross-docking e in stato :status: questo passaggio non e possibile.',
        'door_other_warehouse' => 'La baia non appartiene al magazzino dell\'ordine.',
        'door_direction' => 'La baia non serve il traffico :direction.',
        'duplicate_item' => 'L\'articolo compare su piu righe.',
        'outbound_over_inbound' => 'La quantita in uscita non puo superare la quantita in ingresso di :inbound.',
        'line_not_found' => 'La riga non appartiene a questo ordine di cross-docking.',
    ],
    'dock' => [
        'direction_in_use' => 'La baia ha appuntamenti attivi di altra direzione: la direzione non puo cambiare.',
        'door_in_use' => 'La baia ha ancora appuntamenti attivi o ordini di cross-docking aperti.',
        'not_editable' => 'Un appuntamento in stato :status non puo piu essere modificato.',
        'door_inactive' => 'La baia :door non e attiva.',
        'door_direction' => 'La baia :door non serve il traffico :direction.',
        'ends_before_start' => 'L\'appuntamento deve finire dopo il suo inizio.',
        'conflict' => 'La baia :door e gia prenotata dalle :from alle :to.',
        'cross_dock_other_warehouse' => 'L\'ordine di cross-docking appartiene a un altro magazzino.',
        'not_deletable' => 'Un appuntamento in stato :status non puo essere eliminato.',
        'invalid_transition' => 'L\'appuntamento e in stato :status: questo passaggio non e possibile.',
        'no_show_too_early' => 'L\'appuntamento non e ancora iniziato: non puo essere un mancato arrivo.',
    ],
    'wave' => [
        'rule_inactive' => 'La regola di ondata non e attiva.',
        'not_enough_tasks' => 'Solo :found task di prelievo aperti rispettano la regola, il minimo e :minimum.',
        'tasks_open' => ':count task dell\'ondata sono ancora aperti.',
        'work_started' => 'Il lavoro sull\'ondata e gia iniziato: non si puo annullare.',
        'invalid_transition' => 'L\'ondata e in stato :status: questo passaggio non e possibile.',
    ],
];
