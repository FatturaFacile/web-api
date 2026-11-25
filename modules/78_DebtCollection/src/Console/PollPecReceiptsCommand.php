<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\DebtCollection\Models\Notice;
use Modules\DebtCollection\Services\ReceiptService;
use Modules\SDI\Services\PecMailboxFactory;
use Throwable;

/**
 * Reads the certified mailbox of the companies that sent notices by PEC and records the acceptance and delivery
 * receipts they find. A receipt already recorded for a notice is skipped, so the command is safe to run repeatedly.
 */
final class PollPecReceiptsCommand extends Command
{
    protected $signature = 'debt-collection:poll-pec-receipts {--tenant= : Only this tenant id} {--limit=200 : Most recent messages read per mailbox}';

    protected $description = 'Record the PEC acceptance and delivery receipts of the dunning notices';

    public function handle(TenantContext $context, PecMailboxFactory $mailboxes, ReceiptService $receipts): int
    {
        $found = 0;

        $tenantIds = Notice::withoutGlobalScopes()->where('channel', 'pec')->where('status', 'sent')->when($this->option('tenant'), fn ($query, $tenant) => $query->where('tenant_id', $tenant))->distinct()->pluck('tenant_id');

        foreach (Tenant::query()->whereIn('id', $tenantIds)->whereNotNull('sdi_pec_imap_host')->get() as $tenant) {
            $context->runFor($tenant, function () use ($tenant, $mailboxes, $receipts, &$found): void {
                try {
                    $mailbox = $mailboxes->open($tenant);
                    $uids = array_slice($mailbox->uidsAfter(0), -max(1, (int) $this->option('limit')));

                    foreach ($uids as $uid) {
                        if ($receipts->ingest($mailbox->fetch($uid)) !== null) {
                            $found++;
                        }
                    }

                    $mailbox->close();
                } catch (Throwable $exception) {
                    $this->warn("Tenant {$tenant->id}: ".$exception->getMessage());
                }
            });
        }

        $this->info("PEC receipts recorded: {$found}");

        return self::SUCCESS;
    }
}
