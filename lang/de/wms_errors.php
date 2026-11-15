<?php

declare(strict_types=1);

return [
    'task' => [
        'not_editable' => 'Eine Aufgabe im Status :status kann nicht mehr geaendert werden.',
        'field_required' => 'Eine Aufgabe vom Typ :type benoetigt dieses Feld.',
        'bin_other_warehouse' => 'Der Lagerplatz gehoert nicht zum Lager der Aufgabe.',
        'bin_locked' => 'Der Lagerplatz :bin ist gesperrt: es kann nichts hineinbewegt werden.',
        'same_bin' => 'Der Ziellagerplatz muss sich vom Quelllagerplatz unterscheiden.',
        'not_deletable' => 'Eine Aufgabe im Status :status kann nicht geloescht werden: stornieren Sie sie.',
        'invalid_transition' => 'Die Aufgabe hat den Status :status: dieser Schritt ist nicht moeglich.',
        'over_planned' => 'Die Menge darf die geplanten :planned nicht ueberschreiten.',
        'use_short' => 'Es wurde weniger als die geplanten :planned erledigt: schliessen Sie die Aufgabe mit einem Grund als Teilmenge ab.',
        'short_below_planned' => 'Eine Teilmenge muss unter den geplanten :planned liegen: bestaetigen Sie die Aufgabe stattdessen.',
        'assigned_to_other' => 'Die Aufgabe ist einem anderen Mitarbeiter zugewiesen.',
        'wave_not_released' => 'Die Welle der Aufgabe wurde noch nicht freigegeben.',
        'stock_rejected' => 'Die Lagerbewegung wurde abgelehnt: :reason',
        'not_a_member' => 'Der Benutzer ist kein Mitglied dieses Unternehmens.',
    ],
    'wave_rule' => [
        'bounds' => 'Die Mindestanzahl der Aufgaben darf die Hoechstanzahl nicht ueberschreiten.',
    ],
    'slotting_rule' => [
        'bounds' => 'Die Mindestgeschwindigkeit darf die Hoechstgeschwindigkeit nicht ueberschreiten.',
    ],
    'slotting' => [
        'reason' => 'Artikel der Klasse :class mit :velocity Entnahmen pro Tag liegt auf einem Platz vom Typ :from: er gehoert auf einen Platz vom Typ :to.',
        'stock_moved' => 'Der Artikel lagert nicht mehr im Quelllagerplatz: erzeugen Sie die Empfehlungen neu.',
        'not_pending' => 'Die Empfehlung hat bereits den Status :status.',
    ],
    'cross_dock' => [
        'received_over_inbound' => 'Die erhaltene Menge darf die Eingangsmenge von :inbound nicht ueberschreiten.',
        'received_below_outbound' => 'Die erhaltene Menge darf nicht unter den bereits dem Ausgang zugeordneten :outbound liegen.',
        'outbound_over_available' => 'Die Ausgangsmenge darf die verfuegbaren :available nicht ueberschreiten.',
        'nothing_matched' => 'Dem Ausgang ist noch nichts zugeordnet.',
        'invalid_transition' => 'Der Cross-Docking-Auftrag hat den Status :status: dieser Schritt ist nicht moeglich.',
        'door_other_warehouse' => 'Das Tor gehoert nicht zum Lager des Auftrags.',
        'door_direction' => 'Das Tor bedient keinen :direction-Verkehr.',
        'duplicate_item' => 'Der Artikel steht auf mehr als einer Zeile.',
        'outbound_over_inbound' => 'Die Ausgangsmenge darf die Eingangsmenge von :inbound nicht ueberschreiten.',
        'line_not_found' => 'Die Zeile gehoert nicht zu diesem Cross-Docking-Auftrag.',
    ],
    'dock' => [
        'direction_in_use' => 'Das Tor hat aktive Termine einer anderen Richtung: die Richtung kann nicht geaendert werden.',
        'door_in_use' => 'Das Tor hat noch aktive Termine oder offene Cross-Docking-Auftraege.',
        'not_editable' => 'Ein Termin im Status :status kann nicht mehr geaendert werden.',
        'door_inactive' => 'Das Tor :door ist nicht aktiv.',
        'door_direction' => 'Das Tor :door bedient keinen :direction-Verkehr.',
        'ends_before_start' => 'Der Termin muss nach seinem Beginn enden.',
        'conflict' => 'Das Tor :door ist von :from bis :to bereits gebucht.',
        'cross_dock_other_warehouse' => 'Der Cross-Docking-Auftrag gehoert zu einem anderen Lager.',
        'not_deletable' => 'Ein Termin im Status :status kann nicht geloescht werden.',
        'invalid_transition' => 'Der Termin hat den Status :status: dieser Schritt ist nicht moeglich.',
        'no_show_too_early' => 'Der Termin hat noch nicht begonnen: er kann kein Nichterscheinen sein.',
    ],
    'wave' => [
        'rule_inactive' => 'Die Wellenregel ist nicht aktiv.',
        'not_enough_tasks' => 'Nur :found offene Kommissionieraufgaben passen zur Regel, das Minimum ist :minimum.',
        'tasks_open' => ':count Aufgaben der Welle sind noch offen.',
        'work_started' => 'Die Arbeit an der Welle hat bereits begonnen: sie kann nicht storniert werden.',
        'invalid_transition' => 'Die Welle hat den Status :status: dieser Schritt ist nicht moeglich.',
    ],
];
