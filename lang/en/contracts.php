<?php

declare(strict_types=1);

return [
    'obligation' => [
        'terminated_contract' => 'A terminated contract takes no new obligations.',
        'item_locked' => 'A :status item cannot change.',
        'item_closed' => 'A closed item stays on record.',
        'already_status' => 'This item is already :status.',
    ],
    'party' => [
        'role_exists' => 'This party already holds this role on the contract.',
    ],
    'clause' => [
        'retired_no_versions' => 'A retired clause cannot get new versions.',
        'no_version_to_approve' => 'A clause without a version cannot be approved.',
        'retired_no_reuse' => 'A retired clause cannot be reused.',
        'rank_used' => 'This fallback rank is already used.',
        'no_fallback_rank' => 'The clause has no fallback with this rank.',
    ],
    'lifecycle' => [
        'locked_by_workflow' => 'This contract is locked by an approval workflow: its status changes when the workflow ends.',
        'status_outside' => 'The status [:status] is outside the contract lifecycle and cannot be changed from here.',
        'transition_invalid' => 'A contract cannot go from :from to :to.',
        'end_date_passed' => 'The end date of this contract has passed: renew it with a new end date instead of activating it.',
    ],
    'register' => [
        'renew_status' => 'A contract in status :status cannot be renewed.',
        'renew_no_end' => 'This contract has no end date to extend.',
        'renew_no_term' => 'This contract has no renewal term: state the new end date.',
        'renew_end_after' => 'The new end date must be after the current end date.',
    ],
    'template' => [
        'unapproved_clauses' => 'Only approved clauses can be used in an active template: :codes.',
        'wrong_kind' => 'The template is for :kind contracts.',
        'inactive' => 'Only an active template can be used.',
        'clause_not_approved' => 'Every clause of the template must be approved: :clause.',
        'undeclared_placeholders' => 'Undeclared placeholders in the clauses: :names.',
        'missing_variables' => 'Missing required variables: :names.',
    ],
    'redline' => [
        'same_contract' => 'Both versions must belong to the same contract.',
        'comment_resolved' => 'The comment is already resolved.',
        'round_open' => 'Close the open negotiation round first.',
        'round_closed' => 'The round is already closed.',
    ],
    'signature' => [
        'version_mismatch' => 'The version does not belong to this contract.',
        'pending_exists' => 'This version already has a pending signature request.',
        'only_draft_send' => 'Only a draft request can be sent.',
        'already_signed' => 'This signer already signed.',
        'sign_in_order' => 'Signers sign in order: a previous signer has not signed yet.',
        'only_open_cancel' => 'Only a draft or sent request can be cancelled.',
        'expired' => 'The request expired.',
        'not_open' => 'The request is not waiting for signatures.',
        'token_invalid' => 'The signing token is not valid.',
        'provider_unknown' => 'Unknown signature provider.',
        'link_invalid' => 'This signing link is not valid.',
    ],
];
