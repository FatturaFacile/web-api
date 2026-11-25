<?php

declare(strict_types=1);

return [
    'order' => [
        'approvers_required' => 'Fügen Sie vor dem Absenden mindestens einen Genehmiger hinzu.',
        'affected_required' => 'Fügen Sie vor dem Absenden mindestens einen betroffenen Artikel oder eine Stückliste hinzu.',
        'not_in_review' => 'Die Änderung befindet sich nicht in der Prüfung.',
        'cannot_cancel' => 'Nur eine Änderung im Entwurf, in Prüfung oder genehmigt kann storniert werden.',
        'not_approved' => 'Nur eine genehmigte Änderung kann umgesetzt werden.',
        'not_draft' => 'Nur eine Änderung im Entwurf kann geändert werden.',
        'code_taken' => 'Der Code :code wird bereits von einer anderen Änderung verwendet.',
        'already_implemented' => 'Diese technische Änderung wurde bereits als ECN umgesetzt.',
        'department_approvals_required' => 'Alle vier Abteilungsfreigaben (Technik, Qualität, Einkauf, Produktion) sind erforderlich.',
        'invalid_stage_transition' => 'Ungültiger ECR/ECO/ECN-Statusübergang.',
    ],
    'approval' => [
        'not_your_turn' => 'Sie sind bei dieser Änderung nicht an der Reihe.',
        'reject_comment_required' => 'Eine Ablehnung braucht einen Kommentar.',
    ],
    'revision' => [
        'exists' => 'Die Revision :revision existiert für diesen Artikel bereits.',
        'change_order_required' => 'Der Artikel hat bereits eine freigegebene Revision: geben Sie die neue durch Umsetzen einer Änderung frei.',
        'not_released' => 'Nur eine freigegebene Revision kann obsolet werden.',
        'not_draft' => 'Nur eine Revision im Entwurf kann geändert oder freigegeben werden.',
        'invalid_initial_state' => 'Eine neue Revision kann nicht direkt als freigegeben oder obsolet erstellt werden.',
        'invalid_transition' => 'Ungültiger Lebenszyklus-Übergang der Revision.',
    ],
    'cad' => [
        'empty_payload' => 'Die CAD-Nutzlast ist leer.',
        'no_valid_parts' => 'Keine gültigen Teile im CAD-Export gefunden.',
        'invalid_xml' => 'Die CAD-XML-Datei ist ungültig.',
    ],
    'diff' => [
        'boms_required' => 'Wählen Sie zwei gültige Revisionen oder Stücklisten zum Vergleich.',
    ],
];
