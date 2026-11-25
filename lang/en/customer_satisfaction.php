<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Records retrieved successfully.',
        'loaded' => 'Record retrieved successfully.',
        'saved' => 'Saved successfully.',
        'deleted' => 'Deleted successfully.',
        'defaults_installed' => 'The stock survey templates were installed.',
        'no_matching_rule' => 'No active survey rule matches this event.',
        'dispatch_recorded' => 'The survey dispatch was recorded.',
        'dispatches_processed' => 'The due survey invitations were processed.',
        'report_generated' => 'The ISO 9001 report was generated.',
        'score_recorded' => 'Thank you, your rating was recorded.',
        'feedback_recorded' => 'Thank you, your comment was recorded.',
        'opted_out' => 'You will not receive satisfaction surveys any more.',
    ],

    'errors' => [
        'survey_not_found' => 'This survey link is not valid.',
        'survey_expired' => 'This survey link has expired.',
        'survey_not_available' => 'This survey is not available.',
        'invalid_score' => 'The score must be between :min and :max.',
        'score_required' => 'Give a score first, then add your comment.',
        'comment_required' => 'Write a comment or record a voice note.',
        'voice_not_allowed' => 'Voice comments are not enabled for this survey.',
        'invalid_status_transition' => 'A case cannot move from ":from" to ":to".',
        'resolution_requires_root_cause' => 'Resolving or closing a case needs the root cause and the resolution summary.',
        'unsupported_export_format' => 'Unsupported export format.',
    ],

    'crm_task_subject' => 'Detractor recovery: call :customer within 24 hours',

    'report' => [
        'title' => 'Customer satisfaction report',
        'period' => 'Period',
        'kpis' => 'Key indicators',
        'response_rate' => 'Response rate %',
        'sla_compliance' => '24h SLA compliance %',
        'open_cases' => 'Open detractor cases',
        'breakdown' => 'Breakdown',
        'dimension' => 'Dimension',
        'responses' => 'Responses',
        'corrective_actions' => 'Non-conformities and corrective actions',
        'resolution_rate' => 'Closed-loop resolution rate %',
        'management_review_notes' => 'Management review notes',
        'columns' => [
            'dimension' => 'Dimension',
            'month' => 'Month',
            'responses' => 'Responses',
            'nps' => 'NPS',
            'promoters_pct' => 'Promoters %',
            'passives_pct' => 'Passives %',
            'detractors_pct' => 'Detractors %',
            'csat_pct' => 'CSAT %',
            'csat_average' => 'CSAT average',
            'ces_average' => 'CES average',
            'ces_low_effort_rate' => 'CES low effort %',
        ],
    ],
];
