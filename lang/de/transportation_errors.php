<?php

declare(strict_types=1);

return [
    'carrier' => [
        'in_use' => 'Der Frachtfuehrer hat noch offene Transportauftraege oder Touren.',
    ],
    'tariff' => [
        'valid_to_before_from' => 'Die Tariftabelle kann nicht vor ihrem Beginn enden.',
        'range_order' => 'Die Obergrenze eines Bereichs muss ueber seiner Untergrenze liegen.',
        'band_overlap' => 'Die Stufe ueberschneidet sich mit einer anderen Stufe dieser Tariftabelle fuer dieselben Zonen (Gewicht von :from bis :to).',
    ],
    'rate' => [
        'no_rate' => 'Kein aktiver Tarif von :carrier deckt diese Sendung ab.',
    ],
    'order' => [
        'window_order' => 'Ein Zeitfenster kann nicht vor seinem Beginn enden.',
        'not_editable' => 'Ein Transportauftrag im Status :status kann nicht mehr geaendert werden.',
        'not_deletable' => 'Ein Transportauftrag im Status :status kann nicht geloescht werden: stornieren Sie ihn.',
        'invalid_transition' => 'Der Transportauftrag hat den Status :status: dieser Schritt ist nicht moeglich.',
        'carrier_inactive' => 'Der Frachtfuehrer :carrier ist nicht aktiv.',
        'trip_carrier' => 'Der Auftrag liegt auf einer Tour eines anderen Frachtfuehrers.',
        'on_trip' => 'Der Auftrag liegt auf einer Tour: nehmen Sie ihn zuerst von der Tour.',
    ],
    'trip' => [
        'ends_before_start' => 'Die Tour kann nicht vor ihrem Beginn enden.',
        'not_editable' => 'Eine Tour im Status :status kann nicht mehr geaendert werden.',
        'capacity_below_load' => 'Die Kapazitaet kann nicht unter die bereits gebuendelte Ladung (:load) sinken.',
        'not_deletable' => 'Nur eine geplante Tour ohne Auftraege kann geloescht werden.',
        'order_not_found' => 'Einer der Auftraege existiert in diesem Unternehmen nicht.',
        'order_not_planned' => 'Der Auftrag :number hat den Status :status: nur geplante Auftraege koennen gebuendelt werden.',
        'order_on_trip' => 'Der Auftrag :number liegt bereits auf einer Tour.',
        'order_other_carrier' => 'Der Auftrag :number gehoert zu einem anderen Frachtfuehrer als die Tour.',
        'over_weight' => 'Die Ladung von :total kg ueberschreitet die Kapazitaet von :capacity kg.',
        'over_volume' => 'Die Ladung von :total cbm ueberschreitet die Kapazitaet von :capacity cbm.',
        'order_not_on_trip' => 'Der Auftrag liegt nicht auf dieser Tour.',
        'stops_mismatch' => 'Die Liste muss jeden Halt der Tour genau einmal enthalten.',
        'delivery_before_pickup' => 'Eine Lieferung kann nicht vor der Abholung desselben Auftrags erfolgen.',
        'stop_not_found' => 'Der Halt gehoert nicht zu dieser Tour.',
        'stop_done' => 'Der Halt ist bereits abgeschlossen.',
        'stop_out_of_sequence' => 'Frueherer Halte sind noch offen: schliessen Sie diese zuerst ab.',
        'departure_before_arrival' => 'Die Abfahrt kann nicht vor der Ankunft liegen.',
        'no_stops' => 'Eine Tour ohne Halte kann nicht disponiert werden.',
        'stops_pending' => ':count Halte der Tour sind noch offen.',
        'invalid_transition' => 'Die Tour hat den Status :status: dieser Schritt ist nicht moeglich.',
    ],
    'tracking' => [
        'order_closed' => 'Der Auftrag :number hat den Status :status: er nimmt keine Tracking-Ereignisse an.',
    ],
    'quote' => [
        'no_offers' => 'Kein aktiver Frachtfuehrer hat einen Tarif fuer diese Sendung.',
        'not_open' => 'Das Angebot hat bereits den Status :status.',
        'not_offered' => 'Dieser Frachtfuehrer hat im Angebot kein Angebot abgegeben.',
        'awarded_locked' => 'Ein vergebenes Angebot kann nicht geloescht werden.',
    ],
    'invoice' => [
        'disputed' => 'Die Rechnung ist in Klaerung oder bereits geklaert: sie kann nicht geaendert oder geloescht werden.',
        'resolved' => 'Die Klaerung ist abgeschlossen: die Rechnung kann nicht erneut geprueft werden.',
        'nothing_to_dispute' => 'Pruefen Sie die Rechnung zuerst: nur eine zu hohe, zu niedrige oder nicht zuordenbare Rechnung kann beanstandet werden.',
        'dispute_exists' => 'Die Rechnung hat bereits eine Beanstandung (:status).',
        'no_open_dispute' => 'Die Rechnung hat keine offene Beanstandung.',
        'credit_range' => 'Der gutgeschriebene Betrag darf :limit nicht ueberschreiten.',
        'credit_only_when_credited' => 'Ein gutgeschriebener Betrag gehoert nur zur Loesung Gutschrift.',
        'order_other_carrier' => 'Der Transportauftrag gehoert nicht zum Frachtfuehrer der Rechnung.',
    ],
];
