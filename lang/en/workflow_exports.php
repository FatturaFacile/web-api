<?php

declare(strict_types=1);

return [
    'evidence' => [
        'title' => 'Approval evidence',
        'document' => 'Document', 'requester' => 'Requester', 'amount' => 'Amount (functional currency)', 'workflow' => 'Workflow',
        'status' => 'Status', 'started' => 'Started', 'completed' => 'Completed', 'steps' => 'Approval steps',
        'date' => 'Date', 'time' => 'Time', 'identity' => 'Identity', 'node' => 'Step', 'outcome' => 'Outcome', 'channel' => 'Channel', 'reason' => 'Reason',
        'chain_intact' => 'Audit trail: :count entries, hash chain intact.',
        'chain_broken' => 'Audit trail: :count entries, the hash chain is NOT intact. Do not rely on this evidence.',
        'neutrality' => 'Internal-control evidence (CO art. 728a/728b, D.Lgs. 231/2001, L. 262/2005). This document records who decided what, when and through which channel; it does not calculate taxes and has no fiscal effect.',
    ],
    'status' => [
        'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'skipped' => 'Skipped', 'escalated' => 'Escalated',
        'expired' => 'Expired', 'cancelled' => 'Cancelled', 'changes_requested' => 'Changes requested', 'reassigned' => 'Reassigned',
        'timed_out' => 'Timed out', 'delegated' => 'Delegated',
    ],
    'event' => [
        'instance_started' => 'Approval started', 'step_reassigned' => 'Step reassigned', 'step_reassigned_removed_approver' => 'Step handed over (approver left)',
        'instance_withdrawn' => 'Withdrawn by the requester', 'instance_cancelled' => 'Cancelled', 'instance_cancelled_by_admin' => 'Cancelled by an administrator',
        'instance_blocked' => 'Blocked: no approver', 'changes_requested' => 'Changes requested', 'approval_invalidated' => 'Approval invalidated by an edit', 'instance_expired' => 'Expired',
    ],
    'instance' => [
        'pending' => 'Pending', 'in_progress' => 'In progress', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled',
        'withdrawn' => 'Withdrawn', 'blocked' => 'Blocked', 'expired' => 'Expired',
    ],
    'sla' => [
        'title' => 'Approval SLA report', 'by_definition' => 'By workflow', 'by_node' => 'By step', 'by_approver' => 'By approver',
        'definition' => 'Workflow', 'node' => 'Step', 'approver' => 'Approver', 'steps' => 'Steps', 'on_time' => 'On time', 'late' => 'Late',
        'timed_out' => 'Timed out', 'overdue' => 'Overdue', 'open' => 'Open', 'escalated' => 'Escalated', 'avg_hours' => 'Average hours to decide',
    ],
];
