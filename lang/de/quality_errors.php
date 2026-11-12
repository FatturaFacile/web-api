<?php

declare(strict_types=1);

return [
    'audit' => [
        'only_planned_editable' => 'Das Audit ist :status: Nur ein geplantes Audit kann geändert werden.',
        'checklist_pending' => 'Für :count Checklistenpunkt(e) liegt noch kein Ergebnis vor.',
        'results_in_progress_only' => 'Ergebnisse können erfasst werden, solange das Audit läuft.',
        'findings_in_progress_only' => 'Feststellungen werden erfasst, solange das Audit läuft.',
        'status_expected' => 'Das Audit ist :status: erwartet wird :expected.',
    ],
    'capa' => [
        'nonconformity_closed' => 'Die Nichtkonformität ist :status: Es kann keine neue Massnahme angelegt werden.',
        'action_locked' => 'Die Massnahme ist :status und kann nicht geändert werden.',
        'status_expected' => 'Die Massnahme ist :status: erwartet wird :expected.',
    ],
    'nonconformity' => [
        'actions_unsettled' => 'Jede Korrektur- und Vorbeugemassnahme muss vor dem Abschluss verifiziert oder storniert werden: :numbers.',
        'root_cause_required' => 'Eine grosse oder kritische Nichtkonformität benötigt vor dem Abschluss eine Grundursache.',
        'verified_action_required' => 'Eine grosse oder kritische Nichtkonformität benötigt vor dem Abschluss mindestens eine verifizierte Korrekturmassnahme.',
        'status_expected' => 'Die Nichtkonformität ist :status: erwartet wird :expected.',
        'locked' => 'Die Nichtkonformität ist :status und kann nicht geändert werden.',
        'mfg_reference_source' => 'Ein Fertigungsbezug ist nur für die Quelle Fertigung zulässig.',
        'mfg_not_found' => 'Die Fertigungsabweichung existiert in diesem Unternehmen nicht.',
    ],
    'procedure' => [
        'code_exists' => 'Eine Prozedur mit diesem Code existiert bereits: Überarbeiten Sie sie, um eine neue Version zu erstellen.',
        'not_editable' => 'Die Prozedur ist :status: Nur eine Prozedur im Entwurf oder abgelehnt kann geändert werden.',
        'submit_status' => 'Die Prozedur ist :status: Nur eine Prozedur im Entwurf oder abgelehnt wird eingereicht.',
        'document_required' => 'Verknüpfen Sie das Dokument der Prozedur, bevor Sie sie zur Genehmigung einreichen.',
        'workflow_start_failed' => 'Der Genehmigungsworkflow kann nicht gestartet werden: :reason',
        'decided_by_workflow' => 'Über diese Prozedur entscheidet ihr Genehmigungsworkflow: Verwenden Sie den Workflow-Eingang.',
        'obsolete_only_approved' => 'Nur eine genehmigte Prozedur kann als veraltet gekennzeichnet werden.',
        'revise_only_approved' => 'Nur eine genehmigte Prozedur wird überarbeitet.',
        'author_cannot_approve' => 'Die Autorin oder der Autor einer Prozedur kann sie nicht genehmigen.',
        'not_pending' => 'Die Prozedur ist :status, nicht zur Genehmigung ausstehend.',
        'document_not_found' => 'Das Dokument existiert in diesem Unternehmen nicht.',
    ],
];
