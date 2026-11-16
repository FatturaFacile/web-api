<?php

declare(strict_types=1);

return [
    'attachment' => [
        'file_missing' => 'The file of this attachment is missing from storage.',
    ],
    'link' => [
        'record_not_found' => 'This record does not exist.',
    ],
    'document' => [
        'checked_out' => 'This document is checked out: check it in or cancel the check-out first.',
        'under_retention' => 'This document is under retention until :date.',
        'not_checked_out' => 'This document is not checked out.',
        'checked_out_by_other' => 'This document is checked out by another user.',
        'legal_hold' => 'This document is under legal hold and cannot change.',
    ],
    'approval' => [
        'template_inactive' => 'The approval template is inactive.',
        'template_without_steps' => 'The approval template has no steps.',
        'already_pending' => 'This document already has a pending approval request.',
        'not_pending' => 'This approval request is no longer pending.',
        'not_approver' => 'You are not the approver of the current step.',
        'cannot_cancel' => 'Only the requester or an administrator can cancel this request.',
        'template_in_use' => 'The approval route cannot change while a request is pending on it.',
        'role_cannot_decide' => 'This role cannot decide a step: it has no write access to documents.',
    ],
    'preservation' => [
        'no_hash' => 'A preservation package has no hash to chain.',
    ],
    'ocr' => [
        'unsupported' => 'No OCR engine supports this format.',
    ],
];
