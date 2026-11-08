<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Modules\Invoicing\Models\SalesInvoice;
use Modules\Projects\Models\Project;
use Modules\Projects\Support\Money;

/**
 * Reads what was invoiced to the customer for a project from Invoicing. Only issued (posted) documents count as billed
 * revenue, because only they reach the ledger; credit notes charged to the project reduce it. Drafts are reported
 * apart, as billing in preparation.
 */
final class ProjectInvoiceReader
{
    /**
     * Net amount (VAT excluded) of the issued invoices of the project, less its issued credit notes.
     */
    public function billedRevenue(Project $project): string
    {
        $billed = Money::of(0);

        SalesInvoice::query()
            ->where('project_id', $project->id)
            ->where('status', 'posted')
            ->get(['invoice_type', 'total_net'])
            ->each(function (SalesInvoice $invoice) use (&$billed): void {
                $net = Money::of($invoice->getAttribute('total_net'));
                $billed = $invoice->isCreditNote() ? Money::sub($billed, $net) : Money::add($billed, $net);
            });

        return Money::amount($billed);
    }

    /**
     * Net amount of the draft invoices of the project, not yet issued.
     */
    public function draftBilling(Project $project): string
    {
        $total = SalesInvoice::query()
            ->where('project_id', $project->id)
            ->where('status', 'draft')
            ->where('invoice_type', '!=', 'credit_note')
            ->pluck('total_net')
            ->reduce(static fn (string $carry, mixed $net): string => Money::add($carry, is_numeric($net) ? (string) $net : '0'), '0');

        return Money::amount($total);
    }
}
