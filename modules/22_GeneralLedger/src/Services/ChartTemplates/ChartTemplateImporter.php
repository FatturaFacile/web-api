<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\ChartTemplates;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\GeneralLedger\Models\ChartOfAccount;

/**
 * Loads a standard chart of accounts into a tenant. The import is idempotent: an account whose code already exists
 * (also a deleted one, the code is unique per tenant) is left untouched, so a tenant can load a template over a chart
 * it has already started and run the import again without duplicates.
 */
final class ChartTemplateImporter
{
    /**
     * @return array<string, array{standard: string, name: string, country: string, currency: string, accounts: int}>
     */
    public function templates(): array
    {
        return array_map(
            static fn (array $template): array => [
                'standard' => $template['standard'],
                'name' => $template['name'],
                'country' => $template['country'],
                'currency' => $template['currency'],
                'accounts' => count($template['accounts']),
            ],
            ChartTemplateCatalog::all(),
        );
    }

    /**
     * @return array{template: string, created: int, skipped: int}
     *
     * @throws InvalidArgumentException when the template does not exist
     */
    public function import(string $tenantId, string $templateKey): array
    {
        $template = ChartTemplateCatalog::all()[$templateKey] ?? throw new InvalidArgumentException("Unknown chart template [{$templateKey}]");

        return DB::transaction(function () use ($tenantId, $template, $templateKey): array {
            $parents = array_unique(array_filter(array_column($template['accounts'], 4)));
            $existing = ChartOfAccount::withoutGlobalScopes()->withTrashed()->where('tenant_id', $tenantId)->pluck('id', 'account_code')->all();
            $created = 0;
            $skipped = 0;

            foreach ($template['accounts'] as [$code, $name, $type, $normalBalance, $parentCode, $reportingClass, $reconciliationType]) {
                if (isset($existing[$code])) {
                    $skipped++;

                    continue;
                }

                $account = ChartOfAccount::withoutGlobalScopes()->create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'account_code' => $code,
                    'name' => $name,
                    'standard' => $template['standard'],
                    'account_type' => $type,
                    'normal_balance' => $normalBalance,
                    'currency' => $template['currency'],
                    'parent_account_id' => $parentCode !== null ? ($existing[$parentCode] ?? null) : null,
                    'reporting_class' => $reportingClass,
                    'is_reconciliation_account' => $reconciliationType !== null,
                    'reconciliation_type' => $reconciliationType,
                    'allows_manual_posting' => ! in_array($code, $parents, true),
                    'is_active' => true,
                ]);

                $existing[$code] = $account->id;
                $created++;
            }

            return ['template' => $templateKey, 'created' => $created, 'skipped' => $skipped];
        });
    }
}
