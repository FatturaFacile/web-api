<?php

declare(strict_types=1);

return [
    'audit' => [
        'only_planned_editable' => 'The audit is :status: only a planned audit can be changed.',
        'checklist_pending' => ':count checklist item(s) still have no result.',
        'results_in_progress_only' => 'Results can be recorded while the audit is in progress.',
        'findings_in_progress_only' => 'Findings are raised while the audit is in progress.',
        'status_expected' => 'The audit is :status: expected :expected.',
    ],
    'capa' => [
        'nonconformity_closed' => 'The non-conformity is :status: no new action can be raised.',
        'action_locked' => 'The action is :status and cannot be changed.',
        'status_expected' => 'The action is :status: expected :expected.',
    ],
    'nonconformity' => [
        'actions_unsettled' => 'Every corrective and preventive action must be verified or cancelled before closing: :numbers.',
        'root_cause_required' => 'A major or critical non-conformity needs a root cause before it is closed.',
        'verified_action_required' => 'A major or critical non-conformity needs at least one verified corrective action before it is closed.',
        'status_expected' => 'The non-conformity is :status: expected :expected.',
        'locked' => 'The non-conformity is :status and cannot be changed.',
        'mfg_reference_source' => 'A manufacturing reference is accepted only for the source manufacturing.',
        'mfg_not_found' => 'The manufacturing non-conformance does not exist in this company.',
    ],
    'procedure' => [
        'code_exists' => 'A procedure with this code exists: revise it to create a new version.',
        'not_editable' => 'The procedure is :status: only a draft or rejected procedure can be changed.',
        'submit_status' => 'The procedure is :status: only a draft or rejected procedure is submitted.',
        'document_required' => 'Link the document of the procedure before submitting it for approval.',
        'workflow_start_failed' => 'The approval workflow cannot be started: :reason',
        'decided_by_workflow' => 'This procedure is decided by its approval workflow: use the workflow inbox.',
        'obsolete_only_approved' => 'Only an approved procedure can be made obsolete.',
        'revise_only_approved' => 'Only an approved procedure is revised.',
        'author_cannot_approve' => 'The author of a procedure cannot approve it.',
        'not_pending' => 'The procedure is :status, not pending approval.',
        'document_not_found' => 'The document does not exist in this company.',
    ],
    'spc' => [
        'code_exists' => 'A characteristic with this code exists.',
        'subgroup_locked' => 'The subgroup size cannot change once samples are recorded.',
        'archived' => 'The characteristic is archived: no new samples.',
        'limits_order' => 'Upper limit must be greater than the lower limit.',
        'subgroup_size' => 'A sample needs exactly :size measurements.',
    ],
    'instrument' => [
        'code_exists' => 'An instrument with this code exists.',
        'not_found' => 'Instrument not found in this company.',
        'retired' => 'The instrument is retired.',
        'due_before_calibration' => 'The next due date must be after the calibration date.',
        'error_exceeds_tolerance' => 'The as-found error exceeds the tolerance: record the result as out_of_tolerance.',
        'nonconformity_title' => 'Instrument :code out of tolerance',
        'nonconformity_description' => 'Calibration of :code on :date was out of tolerance: review the results measured since the previous calibration.',
    ],
    'complaint' => [
        'locked' => 'The complaint is :status and cannot be changed.',
        'status_expected' => 'The complaint is :status: expected :expected.',
        'already_linked' => 'A non-conformity is already linked.',
        'capa_other_nonconformity' => 'The CAPA action belongs to another non-conformity.',
        'root_cause_required' => 'A root cause is required before closing.',
        'nonconformity_open' => 'The linked non-conformity is still open.',
        'nonconformity_title' => 'Complaint :number - :customer',
    ],
];
