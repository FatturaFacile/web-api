<?php

declare(strict_types=1);

namespace Modules\Projects\Support;

/**
 * Status vocabularies of the project billing module (Mod 081).
 */
final class BillingStatuses
{
    public const array ContractStatuses = ['draft', 'active', 'on_hold', 'completed', 'closed'];

    /** Contract statuses under which new billing documents may be created. */
    public const array ContractBillable = ['active'];

    public const string SalDraft = 'draft';

    public const string SalPendingApproval = 'pending_approval';

    public const string SalApproved = 'approved';

    public const string SalInvoiced = 'invoiced';

    public const string SalCancelled = 'cancelled';

    public const array RetentionReleaseRules = ['manual', 'on_acceptance', 'on_warranty_expiry', 'on_milestone'];

    public const string WipDraft = 'draft';

    public const string WipPosted = 'posted';

    public const string WipReversed = 'reversed';

    /** WIP valuation methods; milestone progress is cost-independent and derives the ratio from completed milestones. */
    public const array WipMethods = ['cost_to_cost_percentage_of_completion', 'completed_contract', 'milestone_progress'];
}
