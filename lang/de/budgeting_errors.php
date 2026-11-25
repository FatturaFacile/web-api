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
        'formal_immutable' => 'Ein genehmigtes Budget wird nie wieder geöffnet: erstellen Sie stattdessen eine Revision.',
        'baseline_not_approved' => 'Ein revidiertes Budget kann nur genehmigt werden, wenn das Budget, das es revidiert, genehmigt oder gesperrt ist.',
    ],
    'reforecast' => [
        'base_not_approved' => 'Nur eine genehmigte oder gesperrte Version kann neu prognostiziert werden.',
        'no_lines' => 'Die Version hat keine Zeilen zum Neuprognostizieren.',
    ],
    'revision' => [
        'baseline_not_approved' => 'Nur ein genehmigtes oder gesperrtes ursprüngliches oder revidiertes Budget kann revidiert werden.',
        'already_open' => 'Für dieses Geschäftsjahr ist bereits ein revidiertes Budget in Arbeit: genehmigen oder löschen Sie es zuerst.',
        'suffix' => 'Revision :number',
        'adjustment_range' => 'Die Anpassung darf die Beträge nicht unter null senken.',
        'transfer_same' => 'Die beiden Zeilen einer Umbuchung müssen verschieden sein.',
        'transfer_insufficient' => 'Die Zeile, von der übertragen wird, hat in dieser Periode nicht genug Budget.',
        'roll_base' => 'Nur eine genehmigte oder gesperrte Rolling Forecast kann fortgeschrieben werden.',
    ],
    'grid' => [
        'slot_range' => 'Die Spalte muss zwischen 1 und :max liegen.',
    ],
    'settings' => [
        'thresholds' => 'Die Gelb-Schwelle darf nicht höher als die Rot-Schwelle sein.',
    ],
    'override' => [
        'source_required' => 'Eine Ausnahme für einen Beleg braucht den Beleg.',
        'already_open' => 'Für diesen Beleg gibt es bereits eine offene Ausnahmeanfrage.',
        'expiry_past' => 'Das Ablaufdatum darf nicht in der Vergangenheit liegen.',
        'not_open' => 'Nur eine ausstehende oder genehmigte Ausnahme kann storniert werden.',
        'not_pending' => 'Über die Ausnahme wurde bereits entschieden.',
        'four_eyes' => 'Vier-Augen-Prinzip: Sie können nicht über Ihre eigene Ausnahmeanfrage entscheiden.',
    ],
    'spend' => [
        'blocked' => 'Das Budget erlaubt diese Ausgabe nicht: :available :currency verfügbar, :requested :currency angefordert. Beantragen Sie eine Ausnahme.',
        'warning' => 'Diese Ausgabe überschreitet das Budget (:available :currency verfügbar, :requested :currency angefordert).',
        'overridden' => 'Diese Ausgabe überschreitet das Budget und eine genehmigte Ausnahme erlaubt sie (:available :currency verfügbar, :requested :currency angefordert).',
    ],
    'import' => [
        'missing_column' => 'Die Spalte :column fehlt.',
        'account_unknown' => 'Das Konto :code existiert nicht.',
        'cost_center_unknown' => 'Die Kostenstelle :code existiert nicht.',
        'project_unknown' => 'Das Projekt :code existiert nicht.',
        'period_invalid' => 'Die Periode muss eine ganze Zahl von 1 bis :max sein.',
        'amount_invalid' => 'Der Betrag muss eine Zahl größer oder gleich null sein.',
        'number_invalid' => 'Der Wert muss eine Zahl größer oder gleich null sein.',
        'duplicate' => 'Dieses Konto, diese Kostenstelle, dieses Projekt und diese Periode kommen bereits in Zeile :row vor.',
        'too_many_rows' => 'Die Datei hat mehr als :max Zeilen: teilen Sie sie auf.',
        'empty' => 'Die Datei ist leer.',
        'xls_unsupported' => 'Das alte Format .xls wird nicht unterstützt: speichern Sie die Datei als .xlsx oder .csv.',
    ],
];
