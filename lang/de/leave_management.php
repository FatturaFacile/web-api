<?php

declare(strict_types=1);

return [
    'messages' => [
        'calendar_created' => 'Feiertagskalender erstellt.',
        'calendar_updated' => 'Feiertagskalender aktualisiert.',
        'calendar_deleted' => 'Feiertagskalender gelöscht.',
        'policy_created' => 'Anspruchsrichtlinie erstellt.',
        'policy_updated' => 'Anspruchsrichtlinie aktualisiert.',
        'policy_deleted' => 'Anspruchsrichtlinie gelöscht.',
        'accruals_calculated' => 'Monatliche Ferienansprüche berechnet.',
        'request_submitted' => 'Abwesenheitsantrag eingereicht.',
        'sick_registered' => 'Krankheit oder Unfall erfasst.',
        'request_approved' => 'Abwesenheitsantrag genehmigt.',
        'request_rejected' => 'Abwesenheitsantrag abgelehnt.',
        'request_cancelled' => 'Abwesenheitsantrag storniert.',
        'threshold_saved' => 'Mindestbesetzung der Abteilung gespeichert.',
    ],
    'errors' => [
        'unsupported_country' => 'Nicht unterstütztes Land :country (CH oder IT erwartet).',
        'invalid_swiss_canton' => 'Ungültiger Schweizer Kantonscode :canton.',
        'invalid_patron_saint_date' => 'Ungültiges Schutzpatrondatum :date (MM-TT erwartet).',
        'invalid_inps_puc' => 'Ungültiger INPS-PUC-Code ":puc" (9 alphanumerische Zeichen erforderlich).',
        'inps_puc_required' => 'Die 9-stellige INPS-PUC-Nummer ist für Krankmeldungen in Italien erforderlich.',
        'end_date_before_start' => 'Das Enddatum darf nicht vor dem Startdatum liegen.',
        'overlapping_request' => 'Zwischen :start_date und :end_date existiert bereits ein überlappender Antrag.',
        'insufficient_balance' => 'Ungenügendes Ferienguthaben (verfügbar: :available, beantragt: :requested).',
        'staffing_threshold_breached' => 'Mindestbesetzung der Abteilung am :date unterschritten (:present anwesend, :required erforderlich).',
        'invalid_state_transition' => 'Aktion :action im Status :status nicht zulässig.',
        'locked_by_workflow' => 'Dieser Antrag wird durch einen aktiven Genehmigungs-Workflow (Mod 006) gesteuert.',
        'legge_104_limit_exceeded' => 'Monatliches Limit Gesetz 104/1992 überschritten (verbraucht: :used h, beantragt: :requested h, Limit: :limit h).',
        'invalid_decision' => 'Ungültige Entscheidung :decision.',
        'employee_id_required' => 'Der Parameter employee_id ist erforderlich.',
    ],
];
