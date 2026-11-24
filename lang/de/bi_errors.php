<?php

declare(strict_types=1);

return [
    'formula' => [
        'length' => 'Die Formel darf nicht leer sein und höchstens :max Zeichen haben.',
        'number' => 'Die Zahl ":token" ist ungültig: Verwende Ziffern mit höchstens 8 Dezimalstellen.',
        'character' => 'Das Zeichen ":character" ist in einer Formel nicht erlaubt: Verwende Zahlen, Kennzahl-Schlüssel, + - * / und Klammern.',
        'incomplete' => 'Die Formel ist unvollständig.',
        'parenthesis' => 'Eine Klammer ist nicht geschlossen.',
        'unexpected' => 'Unerwartetes ":token" in der Formel.',
        'too_deep' => 'Die Formel ist zu tief verschachtelt.',
        'no_reference' => 'Die Formel muss mindestens eine Kennzahl des Datensatzes verwenden.',
        'unknown_measure' => 'Die Formel verwendet ":measure", das keine Kennzahl des Datensatzes ist.',
    ],
    'calculated' => [
        'key_taken_by_dataset' => 'Der Schlüssel ":key" ist bereits eine Kennzahl oder Dimension des Datensatzes.',
        'key_exists' => 'Eine berechnete Kennzahl mit dem Schlüssel ":key" existiert bereits für diesen Datensatz.',
        'limit' => 'Ein Datensatz kann höchstens :max berechnete Kennzahlen haben.',
        'in_use' => 'Die Kennzahl wird noch verwendet von: :usage. Entferne sie zuerst dort.',
    ],
    'query' => [
        'unknown_calculated' => 'Diese berechnete Kennzahl existiert für den Datensatz nicht.',
    ],
    'pivot' => [
        'measure_missing' => 'Der Pivot-Wert muss eine Kennzahl der Abfrage sein.',
        'dimension_missing' => 'Die Pivot-Zeile oder -Spalte muss eine Dimension der Abfrage sein.',
        'dimension_repeated' => 'Die Dimension ":key" wird in der Pivot zweimal verwendet.',
        'no_values' => 'Wähle mindestens eine Kennzahl für die Zellen der Pivot.',
        'no_axis' => 'Wähle mindestens eine Dimension für die Zeilen oder Spalten der Pivot.',
        'too_large' => 'Die Pivot hätte mehr als :max Zellen: Filtere oder gruppiere die Daten weiter.',
    ],
    'widget' => [
        'limit' => 'Ein Dashboard kann höchstens :max Widgets haben.',
        'source_required' => 'Wähle eine gespeicherte Abfrage oder gib einen Datensatz und eine Spezifikation an.',
        'source_conflict' => 'Verwende entweder eine gespeicherte Abfrage oder einen Datensatz mit Spezifikation, nicht beides.',
        'saved_query_missing' => 'Die gespeicherte Abfrage existiert nicht oder du kannst sie nicht öffnen.',
        'grid_bounds' => 'Das Widget muss innerhalb der :columns Spalten des Rasters bleiben.',
        'overlap' => 'Das Widget überlappt ein anderes Widget des Dashboards.',
        'not_in_dashboard' => 'Dieses Widget gehört nicht zum Dashboard.',
        'drill_too_deep' => 'Es gibt keine weitere Ebene zum Aufschlüsseln.',
        'drill_mismatch' => 'Das Drill-down muss dem Pfad folgen: erwartet wurde die Dimension ":expected".',
        'drill_path_unknown' => 'Der Drill-Pfad muss Dimensionen des Datensatzes nennen.',
        'drill_path_date' => 'Die Datumsdimension ":key" kann nicht in einem Drill-Pfad stehen.',
        'drill_path_repeated' => 'Die Dimension ":key" wiederholt sich im Drill-Pfad.',
    ],
    'share' => [
        'not_member' => 'Der Benutzer ist kein Mitglied dieses Unternehmens.',
        'owner' => 'Der Eigentümer hat bereits vollen Zugriff.',
    ],
    'snapshot' => [
        'owner_gone' => 'Der Eigentümer dieser Abfrage existiert nicht mehr.',
        'owner_left' => 'Der Eigentümer dieser Abfrage ist kein Mitglied des Unternehmens mehr.',
        'not_comparable' => 'Nur ein erfolgreicher Snapshot einer gespeicherten Abfrage kann mit heute verglichen werden.',
    ],
];
