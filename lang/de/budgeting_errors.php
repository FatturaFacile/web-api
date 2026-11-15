<?php

declare(strict_types=1);

return [
    'version' => [
        'locked' => 'Eine gesperrte Version kann nicht geändert werden.',
        'not_draft' => 'Nur eine Version im Entwurf kann geändert werden: diese wurde bereits eingereicht, genehmigt oder gesperrt.',
        'name_taken' => 'Für das Geschäftsjahr :year existiert bereits eine Version mit diesem Namen.',
    ],
    'line' => [
        'duplicate' => 'Für dieses Konto, diese Kostenstelle, dieses Projekt und diese Periode existiert bereits eine Zeile.',
        'period_range' => 'Die Periode muss zwischen 1 und 12 liegen.',
        'account_missing' => 'Das Konto existiert in diesem Unternehmen nicht.',
        'cost_center_missing' => 'Die Kostenstelle existiert in diesem Unternehmen nicht.',
        'project_missing' => 'Das Projekt existiert in diesem Unternehmen nicht.',
    ],
    'spread' => [
        'range' => 'Die Perioden müssen zwischen 1 und 12 liegen und das Ende darf nicht vor dem Anfang liegen.',
        'method' => 'Die Verteilungsregel ist ungültig.',
        'no_seasonal_weights' => 'Die Version hat keine saisonalen Gewichte: legen Sie sie fest, bevor Sie die saisonale Regel verwenden.',
        'weights_count' => 'Es müssen genau zwölf saisonale Gewichte angegeben werden.',
        'weights_invalid' => 'Die saisonalen Gewichte dürfen nicht negativ sein und ihre Summe in den verwendeten Perioden muss größer als null sein.',
        'percentages_count' => 'Geben Sie genau :count Prozentsätze ein, einen pro Periode des Bereichs.',
        'percentages_sum' => 'Die Prozentsätze dürfen nicht negativ sein und müssen zusammen 100 ergeben.',
    ],
    'approval' => [
        'empty' => 'Eine Version ohne Zeilen kann nicht eingereicht werden.',
        'reason_required' => 'Ein Kommentar mit der Begründung ist erforderlich.',
        'budget_exists' => 'Das Budget :name ist für das Geschäftsjahr :year bereits genehmigt: lehnen Sie es zuerst ab oder ersetzen Sie es.',
        'invalid_transition' => 'Die Version ist :status: die Aktion :action ist nicht erlaubt.',
    ],
    'reforecast' => [
        'base_not_approved' => 'Nur eine genehmigte oder gesperrte Version kann neu prognostiziert werden.',
        'no_lines' => 'Die Version hat keine Zeilen zum Neuprognostizieren.',
    ],
];
