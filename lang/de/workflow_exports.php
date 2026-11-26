<?php

declare(strict_types=1);

return [
    'evidence' => [
        'title' => 'Genehmigungsnachweis',
        'document' => 'Dokument', 'requester' => 'Antragstellende Person', 'amount' => 'Betrag (Funktionalwährung)', 'workflow' => 'Ablauf',
        'status' => 'Status', 'started' => 'Gestartet', 'completed' => 'Abgeschlossen', 'steps' => 'Genehmigungsschritte',
        'date' => 'Datum', 'time' => 'Zeit', 'identity' => 'Identität', 'node' => 'Schritt', 'outcome' => 'Ergebnis', 'channel' => 'Kanal', 'reason' => 'Begründung',
        'chain_intact' => 'Prüfpfad: :count Einträge, Hash-Kette intakt.',
        'chain_broken' => 'Prüfpfad: :count Einträge, die Hash-Kette ist NICHT intakt. Verlassen Sie sich nicht auf diesen Nachweis.',
        'neutrality' => 'Nachweis der internen Kontrolle (OR Art. 728a/728b, D.Lgs. 231/2001, L. 262/2005). Das Dokument hält fest, wer wann was über welchen Kanal entschieden hat; es berechnet keine Steuern und hat keine steuerliche Wirkung.',
    ],
    'status' => [
        'pending' => 'Offen', 'approved' => 'Genehmigt', 'rejected' => 'Abgelehnt', 'skipped' => 'Übersprungen', 'escalated' => 'Eskaliert',
        'expired' => 'Abgelaufen', 'cancelled' => 'Abgebrochen', 'changes_requested' => 'Änderungen angefordert', 'reassigned' => 'Neu zugewiesen',
        'timed_out' => 'Zeitüberschreitung', 'delegated' => 'Delegiert',
    ],
    'event' => [
        'instance_started' => 'Genehmigung gestartet', 'step_reassigned' => 'Schritt neu zugewiesen', 'step_reassigned_removed_approver' => 'Schritt übergeben (Genehmigende Person ausgeschieden)',
        'instance_withdrawn' => 'Von der antragstellenden Person zurückgezogen', 'instance_cancelled' => 'Abgebrochen', 'instance_cancelled_by_admin' => 'Von einer Administration abgebrochen',
        'instance_blocked' => 'Blockiert: keine genehmigende Person', 'changes_requested' => 'Änderungen angefordert', 'approval_invalidated' => 'Genehmigung durch eine Änderung ungültig', 'instance_expired' => 'Abgelaufen',
    ],
    'instance' => [
        'pending' => 'Offen', 'in_progress' => 'In Bearbeitung', 'approved' => 'Genehmigt', 'rejected' => 'Abgelehnt', 'cancelled' => 'Abgebrochen',
        'withdrawn' => 'Zurückgezogen', 'blocked' => 'Blockiert', 'expired' => 'Abgelaufen',
    ],
    'sla' => [
        'title' => 'SLA-Bericht der Genehmigungen', 'by_definition' => 'Nach Ablauf', 'by_node' => 'Nach Schritt', 'by_approver' => 'Nach genehmigender Person',
        'definition' => 'Ablauf', 'node' => 'Schritt', 'approver' => 'Genehmigende Person', 'steps' => 'Schritte', 'on_time' => 'Rechtzeitig', 'late' => 'Verspätet',
        'timed_out' => 'Zeitüberschreitung', 'overdue' => 'Überfällig', 'open' => 'Offen', 'escalated' => 'Eskaliert', 'avg_hours' => 'Durchschnittliche Stunden bis zur Entscheidung',
    ],
];
