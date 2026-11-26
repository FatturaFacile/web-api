<?php

declare(strict_types=1);

return [
    'delegation' => [
        'same_user' => 'Delegator and delegate cannot be the identical user.',
        'circular' => 'Circular delegation rejected: the delegate user has already delegated to you.',
        'cycle' => 'Delegation rejected: it would close a delegation chain (A to B to C and back).',
        'overlap' => 'You already have an active delegation covering part of this period.',
    ],
    'control' => [
        'self_approval' => 'Whoever requests an approval cannot approve it.',
        'multi_level' => 'The same user cannot approve two levels of the same workflow.',
        'signing_limit' => 'The amount exceeds your signing limit (:limit).',
    ],
    'lifecycle' => [
        'not_active' => 'The approval is already :status and can no longer be changed.',
        'withdraw_not_requester' => 'Only the requester can withdraw an approval.',
        'withdraw_already_decided' => 'The approval cannot be withdrawn: a decision has already been taken.',
        'reason_required' => 'A reason is required.',
        'reassign_target' => 'The new approver must be a member of the company.',
        'reassign_not_allowed' => 'This step cannot be reassigned.',
        'document_changed' => 'The document changed after approval (:fields): the approval was restarted.',
        'blocked_no_approver' => 'No approver can be resolved for step ":node".',
        'blocked_no_matrix_rule' => 'No approval rule matches this document and no default rule is configured.',
    ],
    'token' => [
        'invalid' => 'This approval link is not valid.',
        'used' => 'This approval link has already been used.',
        'expired' => 'This approval link has expired.',
        'wrong_user' => 'This approval link was issued to another user.',
        'wrong_action' => 'This approval link does not allow this action.',
        'confirm_required' => 'Confirm the decision on the confirmation page.',
        'reauth_required' => 'Sign in again to approve this amount.',
        'reauth_failed' => 'The password or verification code is not correct.',
    ],
    'inbox' => [
        'period_too_long' => 'The period cannot be longer than 366 days.',
    ],
    'definition' => [
        'publish_to_activate' => 'Publish the definition to activate it.',
        'has_running_instances' => 'The definition has running instances: cancel them or deactivate the definition.',
        'graph_locked' => 'Only a draft definition can change its graph: create a new version.',
        'stale' => 'The definition was changed by someone else: reload it and apply your changes again.',
    ],
    'matrix' => [
        'unknown_subject' => 'Unknown subject type.',
        'tier_range' => 'The lower bound of a tier cannot exceed its upper bound.',
        'needs_tier' => 'A matrix needs at least one tier or a fallback approver.',
    ],
    'calendar' => [
        'unknown_country' => 'Holidays can be generated for CH and IT only.',
    ],
];
