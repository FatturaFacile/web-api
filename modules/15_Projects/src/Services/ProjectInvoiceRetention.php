<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Modules\Projects\Models\ProjectSalCertificate;
use Modules\Projects\Support\BillingStatuses;
use Modules\Projects\Support\Money;

/**
 * Guarantee retention (Garantierückbehalt, SIA 118) the customer withholds from the payment of a SAL invoice. The
 * invoice states the full gross amount; the QR-bill (Mod 023) must request only what is actually payable.
 */
final readonly class ProjectInvoiceRetention
{
    /**
     * Retention withheld on the SAL certificates that were invoiced on this invoice; zero for any other invoice.
     */
    public function withheldOn(string $invoiceId): string
    {
        $total = '0';

        ProjectSalCertificate::query()
            ->where('sales_invoice_id', $invoiceId)
            ->where('status', BillingStatuses::SalInvoiced)
            ->get(['id', 'guarantee_retention_withheld'])
            ->each(function (ProjectSalCertificate $sal) use (&$total): void {
                $total = Money::add($total, $sal->guarantee_retention_withheld);
            });

        return Money::amount($total);
    }
}
