<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Console;

use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Modules\GeneralLedger\Actions\PostPassiveInvoiceAction;
use Modules\Invoicing\Actions\PostInvoiceAction;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * Brings to the ledger the documents older than this flow and the supplier invoices SDI delivered while they could not
 * be posted. Sales documents of the legacy flow are recognised by having no creator: drafts of the Invoicing module
 * always have one. Each document stands alone: one that cannot be posted is reported and the others go on.
 */
final class PostPendingDocumentsCommand extends Command
{
    protected $signature = 'general-ledger:post-pending {--tenant=* : Limit to these tenants} {--dry-run : Only count the documents}';

    protected $description = 'Posts issued documents that have not reached the ledger yet, idempotently';

    public function handle(TenantContext $tenantContext, PostInvoiceAction $postInvoice, PostPassiveInvoiceAction $postPassiveInvoice): int
    {
        $failures = 0;
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($query, $ids) => $query->whereIn('id', $ids))->get();

        foreach ($tenants as $tenant) {
            $tenantContext->runWithTenant($tenant, function () use ($postInvoice, $postPassiveInvoice, &$failures): void {
                $invoices = SalesInvoice::query()->where('status', 'draft')->where('invoice_type', '!=', 'proforma')->whereNull('created_by')->orderBy('date')->get();
                $passiveInvoices = PassiveInvoice::query()->whereNull('gl_journal_entry_id')->orderBy('date')->get();

                if ($this->option('dry-run')) {
                    $this->line("{$invoices->count()} sales documents, {$passiveInvoices->count()} supplier documents");

                    return;
                }

                foreach ($invoices as $invoice) {
                    $failures += $this->attempt((string) $invoice->invoice_number, fn () => $postInvoice->issue((string) $invoice->id));
                }

                foreach ($passiveInvoices as $invoice) {
                    $failures += $this->attempt((string) $invoice->invoice_number, fn () => $postPassiveInvoice->execute($invoice));
                }
            });
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function attempt(string $number, callable $post): int
    {
        try {
            $post();

            return 0;
        } catch (InvalidArgumentException $e) {
            $this->error("{$number}: {$e->getMessage()}");

            return 1;
        }
    }
}
