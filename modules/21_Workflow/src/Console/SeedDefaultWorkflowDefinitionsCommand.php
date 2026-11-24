<?php

declare(strict_types=1);

namespace Modules\Workflow\Console;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Modules\Workflow\Actions\SeedDefaultWorkflowDefinitionsAction;

/**
 * Gives the tenants the default single-step approval definitions (owner or admin approves) for the workflow codes the
 * modules start. Codes that already have a definition in a tenant are skipped, so the command can run again.
 */
final class SeedDefaultWorkflowDefinitionsCommand extends Command
{
    protected $signature = 'workflows:seed-defaults
        {--tenant= : Only this tenant id (every active tenant otherwise)}
        {--code=* : Only these workflow codes}';

    protected $description = 'Create the default approval workflow definitions (single step, owner or admin) missing in the tenants';

    public function handle(SeedDefaultWorkflowDefinitionsAction $seeder): int
    {
        $tenantId = $this->option('tenant');
        $codes = $this->option('code') === [] ? null : array_values(array_map('strtoupper', (array) $this->option('code')));

        $tenants = Tenant::query()
            ->when($tenantId, fn ($query) => $query->whereKey($tenantId), fn ($query) => $query->where('status', 'active'))
            ->get(['id', 'name']);

        if ($tenantId !== null && $tenants->isEmpty()) {
            $this->error("Tenant [{$tenantId}] not found.");

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $created = $seeder->execute((string) $tenant->id, $codes);
            $this->info(sprintf('%s: %d definitions created%s', $tenant->name, count($created), $created === [] ? '' : ' ('.implode(', ', $created).')'));
        }

        return self::SUCCESS;
    }
}
