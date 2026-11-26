<?php

declare(strict_types=1);

namespace Modules\Workflow\Contracts;

use Modules\Workflow\Enums\ApprovalOutcome;
use Modules\Workflow\Services\ApprovableRegistry;

/**
 * What a consumer document (purchase order, expense report, DMS document, ...) exposes to the approval engine. The
 * document class is registered once in {@see ApprovableRegistry} under its subject type.
 *
 * The engine never reads consumer tables directly: it asks for the attributes matrices and conditions may use, the
 * requester (for segregation of duties) and tells the document how the approval ended.
 */
interface ApprovableDocument
{
    /**
     * Stable subject type code (`purchase_order`, `expense_report`, `dms_document`, ...).
     */
    public function approvalSubjectType(): string;

    /**
     * Whitelisted attributes the matrices and conditions may evaluate: `amount`, `currency`, `cost_center_id`,
     * `category`, `discount_percent`, `new_supplier`, `company_id`, ... Amounts are in the functional currency of the
     * company (or carry the document rate in `exchange_rate`).
     *
     * @return array<string, scalar|null>
     */
    public function approvalAttributes(): array;

    /**
     * The user who submitted the document (the requester); null for system-generated documents.
     */
    public function requesterId(): ?string;

    /**
     * Applies the end of the approval inside the transaction of the decision: `approved` moves the document on,
     * `rejected` sends it back to draft with the reason in the instance.
     */
    public function onApprovalCompleted(ApprovalOutcome $outcome): void;
}
