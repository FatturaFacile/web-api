<?php

declare(strict_types=1);

return [
    'project' => [
        'number_already_used' => 'Diese Projektnummer wird bereits verwendet.',
        'cannot_start' => 'Das Projekt ist :status: Es kann nicht gestartet werden.',
        'cannot_pause' => 'Das Projekt ist :status: Es kann nicht pausiert werden.',
        'cannot_resume' => 'Das Projekt ist :status: Es kann nicht fortgesetzt werden.',
        'cannot_complete' => 'Das Projekt ist :status: Es kann nicht abgeschlossen werden.',
        'no_longer_changeable' => 'Das Projekt ist :status: Es kann nicht mehr geändert werden.',
        'contract_value_via_change_orders' => 'Der Vertragswert eines gestarteten Projekts ändert sich nur über Änderungsaufträge.',
        'recognition_method_locked' => 'Die Erfassungsmethode kann nicht mehr geändert werden, sobald Kosten auf das Projekt gebucht sind.',
        'cannot_delete_with_bookings' => 'Auf dem Projekt sind Zeiten oder Kosten gebucht: Es kann nicht gelöscht werden.',
    ],
    'rate_card' => [
        'overlaps' => 'Eine andere Tarifkarte mit demselben Geltungsbereich überschneidet sich mit diesem Gültigkeitszeitraum.',
        'managers_only' => 'Nur die Manager des Unternehmens können unternehmensweite Tarife und Kapazitäten ändern.',
    ],
    'cost' => [
        'project_closed' => 'Das Projekt :number ist :status: Es können keine weiteren Kosten gebucht werden.',
        'approve_only_pending' => 'Der Kosteneintrag hat den Status :status, nur ausstehende Einträge können genehmigt werden.',
        'reject_only_pending' => 'Der Kosteneintrag hat den Status :status, nur ausstehende Einträge können abgelehnt werden.',
        'category_invalid' => 'Die Kostenkategorie ist ungültig.',
        'total_negative' => 'Die Gesamtkosten dürfen nicht negativ sein.',
        'allocation_needs_task' => 'Jede Zuordnung muss auf eine Aufgabe des Projekts verweisen.',
        'allocation_not_100' => 'Die Prozentsätze der Zuordnung müssen zusammen 100 ergeben.',
    ],
    'task' => [
        'not_in_project' => 'Die Aufgabe gehört nicht zum Projekt.',
        'cannot_delete_with_bookings' => 'Auf dieser Aufgabe sind Stunden oder Kosten gebucht: Sie kann nicht gelöscht werden.',
        'has_subtasks' => 'Die Aufgabe hat Teilaufgaben: Löschen oder verschieben Sie diese zuerst.',
        'parent_invalid' => 'Das übergeordnete Element muss eine andere Aufgabe desselben Projekts sein.',
    ],
    'phase' => [
        'not_in_project' => 'Die Phase gehört nicht zum Projekt.',
    ],
    'billing' => [
        'milestone_not_completed' => 'Der Meilenstein ist noch nicht abgeschlossen: Schließen Sie ihn vor der Abrechnung ab.',
        'milestone_already_billed' => 'Der Meilenstein ist bereits abgerechnet.',
        'milestone_no_amount' => 'Der Meilenstein hat weder einen Abrechnungsbetrag noch einen Abrechnungsprozentsatz.',
        'nothing_to_invoice' => 'Bis zu diesem Datum gibt es keine genehmigten abrechenbaren Stunden oder Kosten zu fakturieren.',
        'project_closed' => 'Das Projekt :number ist :status: Es kann nicht mehr abgerechnet werden.',
    ],
    'change_order' => [
        'project_closed' => 'Das Projekt :number ist :status: Es können keine Änderungsaufträge hinzugefügt werden.',
        'number_already_used' => 'Diese Nummer des Änderungsauftrags wird im Projekt bereits verwendet.',
        'already_decided' => 'Der Änderungsauftrag hat den Status :status: Er wurde bereits entschieden.',
        'project_closed_apply' => 'Das Projekt :number ist :status: Änderungsaufträge können nicht mehr angewendet werden.',
        'cost_impact_mismatch' => 'Die Kostenauswirkung muss der Summe der Kostenänderungen der Positionen entsprechen.',
        'revenue_impact_mismatch' => 'Die Erlösauswirkung muss der Summe der Erlösänderungen der Positionen entsprechen.',
    ],
    'closing' => [
        'open_timesheets' => ':count Zeitnachweise sind noch Entwurf, eingereicht oder abgelehnt.',
        'pending_expenses' => ':count Spesen warten auf Genehmigung.',
        'unbilled_time_and_materials' => 'Genehmigte abrechenbare Stunden und Kosten im Wert von :amount sind nicht fakturiert.',
        'unbilled_milestones' => ':count abgeschlossene Meilensteine sind nicht fakturiert.',
        'draft_invoices' => ':count Rechnungsentwürfe des Projekts sind noch nicht ausgestellt.',
        'unbilled_recognised_revenue' => 'Der erfasste Erlös übersteigt den fakturierten Erlös um :amount: Fakturieren Sie ihn vor dem Abschluss.',
        'open_commitments' => 'Bestellungen im Wert von :amount sind im Projekt noch offen.',
        'already_closed' => 'Das Projekt :number ist :status: Es kann nicht erneut abgeschlossen werden.',
    ],
    'milestone' => [
        'billed_locked' => 'Der Meilenstein ist bereits abgerechnet und kann nicht mehr geändert werden.',
        'already_completed' => 'Der Meilenstein ist bereits abgeschlossen.',
        'project_closed' => 'Das Projekt :number ist :status: Seine Meilensteine können nicht mehr geändert werden.',
        'not_in_project' => 'Der Meilenstein gehört nicht zum Projekt.',
    ],
    'revenue' => [
        'project_closed' => 'Das Projekt :number ist :status: Erlöse werden darauf nicht mehr erfasst.',
        'no_method' => 'Das Projekt hat keine Methode zur Erlöserfassung: Sein Erlös ist der fakturierte Erlös.',
        'percentage_only_poc' => 'Ein Fertigstellungsgrad kann nur bei der Percentage-of-Completion-Methode angegeben werden.',
        'budget_required' => 'Die Cost-to-Cost-Erfassung benötigt ein genehmigtes Budget, um die Gesamtkosten zu schätzen.',
        'already_recognised_for_date' => 'Der Erlös wurde für dieses Datum bereits erfasst.',
        'already_recognised_later' => 'Der Erlös wurde bereits für ein späteres Datum erfasst: Die Läufe müssen der Datumsreihenfolge folgen.',
    ],
    'schedule' => [
        'end_before_start' => 'Das geplante Enddatum muss am oder nach dem geplanten Startdatum liegen.',
        'dependency_cycle' => 'Die Abhängigkeiten der Aufgaben bilden einen Zyklus: Der kritische Pfad kann nicht berechnet werden.',
        'project_closed' => 'Das Projekt :number ist :status: Sein Terminplan kann nicht mehr geändert werden.',
    ],
    'dependency' => [
        'predecessor_other_project' => 'Der Vorgänger muss eine Aufgabe desselben Projekts sein.',
        'self' => 'Eine Aufgabe kann nicht von sich selbst abhängen.',
        'already_exists' => 'Diese Abhängigkeit besteht bereits.',
        'cycle' => 'Diese Abhängigkeit würde einen Zyklus im Terminplan erzeugen.',
    ],
    'timesheet' => [
        'approve_only_submitted' => 'Der Zeitnachweis hat den Status :status: Nur eingereichte Zeitnachweise können genehmigt werden.',
        'reject_only_submitted' => 'Der Zeitnachweis hat den Status :status: Nur eingereichte Zeitnachweise können abgelehnt werden.',
        'locked' => 'Der Zeitnachweis hat den Status :status und ist gesperrt: Nur Entwürfe oder abgelehnte Zeitnachweise können geändert werden.',
        'project_closed' => 'Das Projekt :number ist :status: Es nimmt keine Zeiten mehr an.',
        'hours_range' => 'Die geleisteten Stunden müssen größer als 0 und höchstens 24 sein.',
        'overtime_range' => 'Die Überstunden müssen zwischen 0 und den geleisteten Stunden liegen.',
        'day_exceeds_24' => 'Die Person hat am :date bereits :hours Stunden gebucht: Ein Tag darf 24 Stunden nicht überschreiten.',
        'rate_missing' => 'Für diese Person ist an diesem Datum kein Kosten- und Abrechnungssatz hinterlegt: Fügen Sie eine Tarifkarte oder eine Projektressource hinzu.',
    ],
];
