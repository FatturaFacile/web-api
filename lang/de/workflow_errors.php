<?php

declare(strict_types=1);

return [
    'delegation' => [
        'same_user' => 'Delegierende und vertretende Person dürfen nicht dieselbe sein.',
        'circular' => 'Zirkuläre Delegation abgelehnt: Die vertretende Person hat bereits an Sie delegiert.',
        'cycle' => 'Delegation abgelehnt: Sie würde eine Delegationskette schliessen (A an B an C und zurück).',
        'overlap' => 'Sie haben bereits eine aktive Delegation, die einen Teil dieses Zeitraums abdeckt.',
    ],
    'control' => [
        'self_approval' => 'Wer eine Genehmigung beantragt, kann sie nicht selbst genehmigen.',
        'multi_level' => 'Dieselbe Person darf nicht zwei Stufen desselben Ablaufs genehmigen.',
        'signing_limit' => 'Der Betrag übersteigt Ihr Zeichnungslimit (:limit).',
    ],
    'lifecycle' => [
        'not_active' => 'Die Genehmigung ist bereits :status und kann nicht mehr geändert werden.',
        'withdraw_not_requester' => 'Nur die antragstellende Person kann eine Genehmigung zurückziehen.',
        'withdraw_already_decided' => 'Die Genehmigung kann nicht zurückgezogen werden: Es wurde bereits entschieden.',
        'reason_required' => 'Eine Begründung ist erforderlich.',
        'reassign_target' => 'Die neue genehmigende Person muss Mitglied des Unternehmens sein.',
        'reassign_not_allowed' => 'Dieser Schritt kann nicht neu zugewiesen werden.',
        'document_changed' => 'Das Dokument wurde nach der Genehmigung geändert (:fields): Die Genehmigung wurde neu gestartet.',
        'blocked_no_approver' => 'Für den Schritt ":node" kann keine genehmigende Person ermittelt werden.',
        'blocked_no_matrix_rule' => 'Keine Genehmigungsregel passt zum Dokument und es ist keine Standardregel konfiguriert.',
    ],
    'token' => [
        'invalid' => 'Dieser Genehmigungslink ist ungültig.',
        'used' => 'Dieser Genehmigungslink wurde bereits verwendet.',
        'expired' => 'Dieser Genehmigungslink ist abgelaufen.',
        'wrong_user' => 'Dieser Genehmigungslink wurde für eine andere Person ausgestellt.',
        'wrong_action' => 'Dieser Genehmigungslink erlaubt diese Aktion nicht.',
        'confirm_required' => 'Bestätigen Sie die Entscheidung auf der Bestätigungsseite.',
        'reauth_required' => 'Melden Sie sich erneut an, um diesen Betrag zu genehmigen.',
        'reauth_failed' => 'Das Passwort oder der Bestätigungscode ist nicht korrekt.',
    ],
    'inbox' => [
        'period_too_long' => 'Der Zeitraum darf nicht länger als 366 Tage sein.',
    ],
    'definition' => [
        'publish_to_activate' => 'Veröffentlichen Sie die Definition, um sie zu aktivieren.',
        'has_running_instances' => 'Die Definition hat laufende Instanzen: Brechen Sie diese ab oder deaktivieren Sie die Definition.',
        'graph_locked' => 'Nur eine Definition im Entwurf kann ihren Graphen ändern: Erstellen Sie eine neue Version.',
        'stale' => 'Die Definition wurde von jemand anderem geändert: Laden Sie sie neu und wenden Sie Ihre Änderungen erneut an.',
    ],
    'matrix' => [
        'unknown_subject' => 'Unbekannter Dokumenttyp.',
        'tier_range' => 'Die Untergrenze einer Stufe darf die Obergrenze nicht überschreiten.',
        'needs_tier' => 'Eine Matrix benötigt mindestens eine Stufe oder eine Ersatzperson.',
    ],
    'calendar' => [
        'unknown_country' => 'Feiertage können nur für CH und IT erzeugt werden.',
    ],
];
