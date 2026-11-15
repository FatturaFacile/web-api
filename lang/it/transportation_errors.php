<?php

declare(strict_types=1);

return [
    'carrier' => [
        'in_use' => 'Il vettore ha ancora ordini di trasporto o viaggi aperti.',
    ],
    'tariff' => [
        'valid_to_before_from' => 'Il tariffario non puo terminare prima del suo inizio.',
        'range_order' => 'Il limite superiore di un intervallo deve essere maggiore del limite inferiore.',
        'band_overlap' => 'La fascia si sovrappone a un\'altra fascia di questo tariffario per le stesse zone (peso da :from a :to).',
    ],
    'rate' => [
        'no_rate' => 'Nessun tariffario attivo di :carrier copre questa spedizione.',
    ],
    'order' => [
        'window_order' => 'Una finestra non puo terminare prima del suo inizio.',
        'not_editable' => 'Un ordine di trasporto in stato :status non puo piu essere modificato.',
        'not_deletable' => 'Un ordine di trasporto in stato :status non puo essere eliminato: annullalo.',
        'invalid_transition' => 'L\'ordine di trasporto e in stato :status: questo passaggio non e possibile.',
        'carrier_inactive' => 'Il vettore :carrier non e attivo.',
        'trip_carrier' => 'L\'ordine e su un viaggio di un altro vettore.',
        'on_trip' => 'L\'ordine e su un viaggio: toglilo prima dal viaggio.',
    ],
    'trip' => [
        'ends_before_start' => 'Il viaggio non puo terminare prima del suo inizio.',
        'not_editable' => 'Un viaggio in stato :status non puo piu essere modificato.',
        'capacity_below_load' => 'La capacita non puo scendere sotto il carico gia consolidato (:load).',
        'not_deletable' => 'Si puo eliminare solo un viaggio pianificato senza ordini.',
        'order_not_found' => 'Uno degli ordini non esiste in questa azienda.',
        'order_not_planned' => 'L\'ordine :number e in stato :status: si possono consolidare solo ordini pianificati.',
        'order_on_trip' => 'L\'ordine :number e gia su un viaggio.',
        'order_other_carrier' => 'L\'ordine :number appartiene a un vettore diverso da quello del viaggio.',
        'over_weight' => 'Il carico di :total kg supera la capacita di :capacity kg.',
        'over_volume' => 'Il carico di :total mc supera la capacita di :capacity mc.',
        'order_not_on_trip' => 'L\'ordine non e su questo viaggio.',
        'stops_mismatch' => 'L\'elenco deve contenere ogni tappa del viaggio una sola volta.',
        'delivery_before_pickup' => 'Una consegna non puo precedere il ritiro dello stesso ordine.',
        'stop_not_found' => 'La tappa non appartiene a questo viaggio.',
        'stop_done' => 'La tappa e gia completata.',
        'stop_out_of_sequence' => 'Ci sono ancora tappe precedenti da completare: completale prima.',
        'departure_before_arrival' => 'La partenza non puo essere prima dell\'arrivo.',
        'no_stops' => 'Un viaggio senza tappe non puo essere avviato.',
        'stops_pending' => ':count tappe del viaggio sono ancora da completare.',
        'invalid_transition' => 'Il viaggio e in stato :status: questo passaggio non e possibile.',
    ],
    'tracking' => [
        'order_closed' => 'L\'ordine :number e in stato :status: non accetta eventi di tracking.',
    ],
    'quote' => [
        'no_offers' => 'Nessun vettore attivo ha un tariffario per questa spedizione.',
        'not_open' => 'Il preventivo e gia in stato :status.',
        'not_offered' => 'Questo vettore non ha fatto offerte nel preventivo.',
        'awarded_locked' => 'Un preventivo assegnato non puo essere eliminato.',
    ],
    'invoice' => [
        'disputed' => 'La fattura e in contestazione o gia risolta: non si puo modificare ne eliminare.',
        'resolved' => 'La contestazione e risolta: la fattura non si puo verificare di nuovo.',
        'nothing_to_dispute' => 'Verifica prima la fattura: si puo contestare solo una fattura sovrafatturata, sottofatturata o non abbinata.',
        'dispute_exists' => 'La fattura ha gia una contestazione (:status).',
        'no_open_dispute' => 'La fattura non ha contestazioni aperte.',
        'credit_range' => 'L\'importo accreditato non puo superare :limit.',
        'credit_only_when_credited' => 'Un importo accreditato e ammesso solo con la risoluzione accreditata.',
        'order_other_carrier' => 'L\'ordine di trasporto non appartiene al vettore della fattura.',
    ],
];
