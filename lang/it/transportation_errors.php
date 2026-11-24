<?php

declare(strict_types=1);

return [
    'carrier' => [
        'in_use' => 'Il vettore ha ancora ordini di trasporto o viaggi aperti.',
    ],
    'tariff' => [
        'valid_to_before_from' => 'Il tariffario non può terminare prima del suo inizio.',
        'range_order' => 'Il limite superiore di un intervallo deve essere maggiore del limite inferiore.',
        'band_overlap' => 'La fascia si sovrappone a un\'altra fascia di questo tariffario per le stesse zone (peso da :from a :to).',
    ],
    'rate' => [
        'no_rate' => 'Nessun tariffario attivo di :carrier copre questa spedizione.',
    ],
    'order' => [
        'window_order' => 'Una finestra non può terminare prima del suo inizio.',
        'not_editable' => 'Un ordine di trasporto in stato :status non può più essere modificato.',
        'not_deletable' => 'Un ordine di trasporto in stato :status non può essere eliminato: annullalo.',
        'invalid_transition' => 'L\'ordine di trasporto è in stato :status: questo passaggio non è possibile.',
        'carrier_inactive' => 'Il vettore :carrier non è attivo.',
        'trip_carrier' => 'L\'ordine è su un viaggio di un altro vettore.',
        'on_trip' => 'L\'ordine è su un viaggio: toglilo prima dal viaggio.',
    ],
    'trip' => [
        'ends_before_start' => 'Il viaggio non può terminare prima del suo inizio.',
        'not_editable' => 'Un viaggio in stato :status non può più essere modificato.',
        'capacity_below_load' => 'La capacità non può scendere sotto il carico già consolidato (:load).',
        'not_deletable' => 'Si può eliminare solo un viaggio pianificato senza ordini.',
        'order_not_found' => 'Uno degli ordini non esiste in questa azienda.',
        'order_not_planned' => 'L\'ordine :number è in stato :status: si possono consolidare solo ordini pianificati.',
        'order_on_trip' => 'L\'ordine :number è già su un viaggio.',
        'order_other_carrier' => 'L\'ordine :number appartiene a un vettore diverso da quello del viaggio.',
        'over_weight' => 'Il carico di :total kg supera la capacità di :capacity kg.',
        'over_volume' => 'Il carico di :total mc supera la capacità di :capacity mc.',
        'order_not_on_trip' => 'L\'ordine non è su questo viaggio.',
        'stops_mismatch' => 'L\'elenco deve contenere ogni tappa del viaggio una sola volta.',
        'delivery_before_pickup' => 'Una consegna non può precedere il ritiro dello stesso ordine.',
        'stop_not_found' => 'La tappa non appartiene a questo viaggio.',
        'stop_done' => 'La tappa è già completata.',
        'stop_out_of_sequence' => 'Ci sono ancora tappe precedenti da completare: completale prima.',
        'departure_before_arrival' => 'La partenza non può essere prima dell\'arrivo.',
        'no_stops' => 'Un viaggio senza tappe non può essere avviato.',
        'stops_pending' => ':count tappe del viaggio sono ancora da completare.',
        'invalid_transition' => 'Il viaggio è in stato :status: questo passaggio non è possibile.',
    ],
    'tracking' => [
        'order_closed' => 'L\'ordine :number è in stato :status: non accetta eventi di tracking.',
    ],
    'quote' => [
        'no_offers' => 'Nessun vettore attivo ha un tariffario per questa spedizione.',
        'not_open' => 'Il preventivo è già in stato :status.',
        'not_offered' => 'Questo vettore non ha fatto offerte nel preventivo.',
        'awarded_locked' => 'Un preventivo assegnato non può essere eliminato.',
    ],
    'invoice' => [
        'disputed' => 'La fattura è in contestazione o già risolta: non si può modificare né eliminare.',
        'resolved' => 'La contestazione è risolta: la fattura non si può verificare di nuovo.',
        'nothing_to_dispute' => 'Verifica prima la fattura: si può contestare solo una fattura sovrafatturata, sottofatturata o non abbinata.',
        'dispute_exists' => 'La fattura ha già una contestazione (:status).',
        'no_open_dispute' => 'La fattura non ha contestazioni aperte.',
        'credit_range' => 'L\'importo accreditato non può superare :limit.',
        'credit_only_when_credited' => 'Un importo accreditato è ammesso solo con la risoluzione accreditata.',
        'order_other_carrier' => 'L\'ordine di trasporto non appartiene al vettore della fattura.',
    ],
];
