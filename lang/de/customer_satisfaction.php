<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Liste erfolgreich abgerufen.',
        'loaded' => 'Datensatz erfolgreich abgerufen.',
        'saved' => 'Erfolgreich gespeichert.',
        'deleted' => 'Erfolgreich gelöscht.',
        'defaults_installed' => 'Die Standard-Umfragevorlagen wurden installiert.',
        'no_matching_rule' => 'Keine aktive Umfrageregel passt zu diesem Ereignis.',
        'dispatch_recorded' => 'Der Umfrageversand wurde erfasst.',
        'dispatches_processed' => 'Die fälligen Umfrageeinladungen wurden verarbeitet.',
        'report_generated' => 'Der ISO-9001-Bericht wurde erstellt.',
        'score_recorded' => 'Danke, Ihre Bewertung wurde erfasst.',
        'feedback_recorded' => 'Danke, Ihr Kommentar wurde erfasst.',
        'opted_out' => 'Sie erhalten keine Zufriedenheitsumfragen mehr.',
    ],

    'errors' => [
        'survey_not_found' => 'Dieser Umfragelink ist ungültig.',
        'survey_expired' => 'Dieser Umfragelink ist abgelaufen.',
        'survey_not_available' => 'Diese Umfrage ist nicht verfügbar.',
        'invalid_score' => 'Die Bewertung muss zwischen :min und :max liegen.',
        'score_required' => 'Geben Sie zuerst eine Bewertung ab und ergänzen Sie dann Ihren Kommentar.',
        'comment_required' => 'Schreiben Sie einen Kommentar oder nehmen Sie eine Sprachnachricht auf.',
        'voice_not_allowed' => 'Sprachkommentare sind für diese Umfrage nicht aktiviert.',
        'invalid_status_transition' => 'Ein Fall kann nicht von ":from" nach ":to" wechseln.',
        'resolution_requires_root_cause' => 'Zum Lösen oder Schliessen eines Falls sind die Grundursache und die Lösungszusammenfassung nötig.',
        'unsupported_export_format' => 'Nicht unterstütztes Exportformat.',
    ],

    'crm_task_subject' => 'Detraktoren-Rückgewinnung: :customer innerhalb von 24 Stunden anrufen',

    'report' => [
        'title' => 'Kundenzufriedenheitsbericht',
        'period' => 'Zeitraum',
        'kpis' => 'Kennzahlen',
        'response_rate' => 'Antwortquote %',
        'sla_compliance' => 'Einhaltung SLA 24h %',
        'open_cases' => 'Offene Detraktoren-Fälle',
        'breakdown' => 'Aufschlüsselung',
        'dimension' => 'Dimension',
        'responses' => 'Antworten',
        'corrective_actions' => 'Nichtkonformitäten und Korrekturmassnahmen',
        'resolution_rate' => 'Lösungsquote Closed-Loop %',
        'management_review_notes' => 'Notizen der Managementbewertung',
        'columns' => [
            'dimension' => 'Dimension',
            'month' => 'Monat',
            'responses' => 'Antworten',
            'nps' => 'NPS',
            'promoters_pct' => 'Promotoren %',
            'passives_pct' => 'Passive %',
            'detractors_pct' => 'Detraktoren %',
            'csat_pct' => 'CSAT %',
            'csat_average' => 'CSAT Durchschnitt',
            'ces_average' => 'CES Durchschnitt',
            'ces_low_effort_rate' => 'CES geringer Aufwand %',
        ],
    ],
];
