<?php

declare(strict_types=1);

return [
    'factor' => [
        'unit_not_allowed' => 'Die Einheit :unit ist für den Aktivitätstyp :type nicht zulässig.',
        'in_use' => 'Der Faktor wird von :count Aktivitätsdatensätzen verwendet: Legen Sie eine neue Version an, statt Wert, Einheit oder Aktivitätstyp zu ändern.',
        'missing_for_activity' => 'Für :type in der Region :region gibt es keinen aktiven Emissionsfaktor.',
        'type_mismatch' => 'Der Emissionsfaktor gilt für :expected, nicht für :type.',
        'inactive' => 'Der Emissionsfaktor ist nicht aktiv.',
        'unit_mismatch' => 'Der Faktor bezieht sich auf :factor_unit, die Menge ist in :unit angegeben.',
        'latest_version_only' => 'Nur die letzte Version eines Faktors kann erneut versioniert werden.',
    ],
    'period' => [
        'overlap' => 'Der Zeitraum überschneidet sich mit dem Berichtszeitraum :name.',
        'closed' => 'Der Berichtszeitraum :name ist abgeschlossen.',
        'status_expected' => 'Der Berichtszeitraum ist :status: erwartet :expected.',
    ],
    'activity' => [
        'locked' => 'Der Berichtszeitraum :name ist abgeschlossen: seine Aktivitätsdaten können nicht mehr geändert werden.',
    ],
    'target' => [
        'status_expected' => 'Das Ziel ist :status: erwartet :expected.',
        'locked' => 'Nur ein Zielentwurf kann Bereich, Basisjahr, Zieljahr und Prozentsatz ändern.',
        'year_order' => 'Das Zieljahr muss nach dem Basisjahr liegen.',
        'baseline_missing' => 'Für :scope sind in :year keine Emissionen erfasst: die Baseline kann nicht festgeschrieben werden.',
        'not_yet_decidable' => 'Das Zieljahr :year ist noch nicht abgeschlossen und das Ziel ist noch nicht erreicht.',
    ],
    'kpi' => [
        'inactive' => 'Der KPI ist nicht aktiv.',
        'period_closed' => 'Der Berichtszeitraum :name ist abgeschlossen: die KPI-Werte können nicht mehr geändert werden.',
    ],
    'materiality' => [
        'locked' => 'Eine genehmigte Wesentlichkeitsanalyse kann nicht geändert werden.',
        'no_topics' => 'Die Analyse benötigt vor der Genehmigung mindestens ein Thema.',
        'status_expected' => 'Die Analyse ist :status: erwartet :expected.',
    ],
];
