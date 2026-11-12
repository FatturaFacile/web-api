<?php

declare(strict_types=1);

return [
    'report' => [
        'unknown_dataset' => 'Unbekannter Datensatz.',
        'role_cannot_read' => 'Ihre Rolle darf die Daten des Moduls :module, aus denen dieser Datensatz stammt, nicht lesen.',
        'no_active_company' => 'Kein aktives Unternehmen.',
        'timeout' => 'Der Bericht hat länger als :seconds Sekunden gedauert und wurde abgebrochen: Schränken Sie den Zeitraum ein, fügen Sie Filter hinzu oder gruppieren Sie nach weniger Dimensionen.',
    ],
    'spec' => [
        'dimensions_list' => 'Dimensionen müssen eine Liste mit höchstens :max Einträgen sein.',
        'dimension_repeated' => 'Die Dimension [:key] ist doppelt vorhanden.',
        'measures_count' => 'Wählen Sie zwischen 1 und :max Kennzahlen.',
        'unknown_measure' => 'Unbekannte Kennzahl für diesen Datensatz.',
        'measure_repeated' => 'Die Kennzahl [:measure] ist doppelt vorhanden.',
        'unknown_comparison' => 'Unbekannter Vergleich.',
        'comparison_needs_period' => 'Ein Vergleich benötigt einen Datensatz mit Zeitraum und einen Zeitraum.',
        'dimension_format' => 'Eine Dimension ist ein Schlüssel, optional gefolgt von :granularity.',
        'unknown_dimension' => 'Unbekannte Dimension für diesen Datensatz.',
        'unknown_granularity' => 'Unbekannte Granularität für diese Dimension.',
        'filters_list' => 'Filter müssen eine Liste mit höchstens :max Einträgen sein.',
        'unknown_dimension_or_measure' => 'Unbekannte Dimension oder Kennzahl für diesen Datensatz.',
        'operator_not_allowed' => 'Operator für dieses Feld nicht zulässig.',
        'invalid_filter_value' => 'Ungültiger Wert für eine Dimension vom Typ :type und den Operator :operator.',
        'sort_list' => 'Die Sortierung muss eine Liste mit höchstens :max Einträgen sein.',
        'sort_field' => 'Sortieren Sie nur nach einer gewählten Dimension oder Kennzahl.',
        'sort_direction' => 'Die Richtung ist asc oder desc.',
        'sort_field_repeated' => 'Das Feld wird bereits zum Sortieren verwendet.',
        'limit_range' => 'Das Top-N-Limit ist eine ganze Zahl zwischen 1 und :max.',
        'no_period' => 'Dieser Datensatz beschreibt den heutigen Stand und hat keinen Zeitraum.',
        'period_format' => 'Der Zeitraum ist ein Objekt mit einer Voreinstellung oder mit from und to.',
        'unknown_preset' => 'Unbekannte Voreinstellung.',
        'period_dates' => 'from und to sind Datumsangaben (Y-m-d).',
        'period_inverted' => 'Der Beginn des Zeitraums liegt nach seinem Ende.',
        'period_too_long' => 'Der Zeitraum darf nicht länger als :days Tage sein.',
    ],
];
