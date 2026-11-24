<?php

declare(strict_types=1);

return [
    'scenario' => [
        'locked' => 'Ein gesperrtes Szenario kann nicht geändert werden.',
        'not_draft' => 'Nur ein Szenario im Entwurf kann geändert werden: dieses ist bereits in Prüfung, genehmigt oder gesperrt.',
        'horizon_order' => 'Das Ende des Horizonts darf nicht vor dem Anfang liegen.',
        'horizon_too_long' => 'Der Horizont darf höchstens :months Monate lang sein.',
        'lines_outside_horizon' => 'Einige Planzeilen liegen außerhalb des neuen Horizonts: entfernen Sie sie zuerst.',
        'published' => 'Das Szenario wurde veröffentlicht und kann weder gelöscht noch in den Entwurf zurückgesetzt werden.',
        'baseline_exists' => 'Das Unternehmen hat bereits ein Basisszenario: legen Sie ein What-if-Szenario an oder kopieren Sie das Basisszenario.',
    ],
    'resource' => [
        'code_taken' => 'Der Ressourcencode :code wird bereits verwendet.',
        'rate_exists' => 'Der Artikel hat bereits eine Belastung auf dieser Ressource.',
        'in_use' => 'Die Ressource hat Kapazitätsbeschränkungen in Szenarien, die nicht mehr im Entwurf sind, und kann nicht gelöscht werden.',
    ],
    'line' => [
        'outside_horizon' => 'Der Monat liegt außerhalb des Horizonts des Szenarios.',
        'duplicate' => 'Für diese Seite, diesen Artikel und diesen Monat existiert bereits eine Zeile.',
        'item_missing' => 'Der Artikel existiert in diesem Unternehmen nicht.',
        'item_required' => 'Wählen Sie einen Artikel oder geben Sie einen Artikelcode ein.',
    ],
    'cycle' => [
        'empty' => 'Ein Szenario ohne Planzeilen kann nicht eingereicht werden.',
        'capacity_overloaded' => ':count Ressourcenmonate sind überlastet: korrigieren Sie den Plan oder akzeptieren Sie die Überlastung mit einem Kommentar.',
        'overload_comment' => 'Das Akzeptieren einer Kapazitätsüberlastung erfordert einen Kommentar.',
        'reason_required' => 'Ein Kommentar mit der Begründung ist erforderlich.',
        'invalid_transition' => 'Das Szenario ist :status: die Aktion :action ist nicht erlaubt.',
        'not_publishable' => 'Nur ein genehmigtes oder gesperrtes Szenario kann veröffentlicht werden.',
    ],
];
