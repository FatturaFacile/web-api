<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Commands;

use Database\Seeders\ChartOfAccountTemplateSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Models\AccountMapping;
use Modules\GeneralLedger\Models\ChartAccountTemplate;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\VatCode;

final readonly class ProvisionChartTemplateHandler
{
    /**
     * @return array{
     *     template: string,
     *     created_count: int,
     *     skipped_count: int,
     *     mappings_created: int,
     *     accounts: list<ChartOfAccount>,
     * }
     */
    public function handle(ProvisionChartTemplateCommand $command): array
    {
        $templateRecord = ChartAccountTemplate::query()
            ->where('key', $command->templateKey)
            ->where('is_active', true)
            ->first();

        $definitions = ChartOfAccountTemplateSeeder::definitions();
        $definition = $definitions[$command->templateKey] ?? null;

        if ($templateRecord === null && $definition === null) {
            throw ValidationException::withMessages([
                'template' => [__('general_ledger_errors.template.not_found', ['template' => $command->templateKey])],
            ]);
        }

        /** @var list<array<string, mixed>> $nodes */
        $nodes = $templateRecord !== null ? $templateRecord->nodes : $definition['nodes'];
        /** @var list<array<string, mixed>> $defaultMappings */
        $defaultMappings = $templateRecord !== null ? ($templateRecord->default_mappings ?? []) : ($definition['default_mappings'] ?? []);
        $standard = $templateRecord !== null ? $templateRecord->standard : $definition['standard'];
        $currency = $templateRecord !== null ? $templateRecord->currency : $definition['currency'];
        $locale = in_array($command->locale, ['it', 'en', 'de', 'fr'], true) ? $command->locale : 'it';

        return DB::transaction(function () use ($command, $nodes, $defaultMappings, $standard, $currency, $locale): array {
            $existingAccounts = ChartOfAccount::withoutGlobalScopes()
                ->where('tenant_id', $command->tenantId)
                ->get()
                ->keyBy('account_code');

            $vatCodesByCode = VatCode::withoutGlobalScopes()
                ->where(static function ($query) use ($command): void {
                    $query->where('tenant_id', $command->tenantId)
                        ->orWhereNull('tenant_id');
                })
                ->get()
                ->keyBy('code');

            $createdCount = 0;
            $skippedCount = 0;
            $created = [];

            foreach ($nodes as $node) {
                $code = (string) $node['code'];

                if ($existingAccounts->has($code)) {
                    $skippedCount++;

                    continue;
                }

                $parentCode = isset($node['parent_code']) && $node['parent_code'] !== ''
                    ? (string) $node['parent_code']
                    : null;
                $parent = $parentCode !== null ? $existingAccounts->get($parentCode) : null;

                /** @var array<string, string> $names */
                $names = is_array($node['names'] ?? null) ? $node['names'] : [];
                $name = $names[$locale] ?? $names['it'] ?? $names['en'] ?? $code;
                /** @var array<string, string> $descriptions */
                $descriptions = is_array($node['descriptions'] ?? null) ? $node['descriptions'] : [];
                $description = $descriptions[$locale] ?? $descriptions['it'] ?? null;

                $vatCodeHint = isset($node['vat_code_hint']) && $node['vat_code_hint'] !== ''
                    ? (string) $node['vat_code_hint']
                    : null;
                $vatCodeId = $vatCodeHint !== null ? $vatCodesByCode->get($vatCodeHint)?->id : null;

                $isPostable = (bool) ($node['is_postable'] ?? true);
                $level = $parent instanceof ChartOfAccount ? ((int) $parent->level + 1) : 1;
                $hierarchyPath = $parent instanceof ChartOfAccount && $parent->hierarchy_path !== null
                    ? $parent->hierarchy_path.'/'.$code
                    : $code;

                $reconciliationType = $node['reconciliation_type'] ?? null;

                $account = ChartOfAccount::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $command->tenantId,
                    'company_id' => $command->companyId,
                    'account_code' => $code,
                    'name' => $name,
                    'description' => $description,
                    'standard' => $standard,
                    'currency' => $currency,
                    'account_type' => (string) ($node['type'] ?? 'asset'),
                    'normal_balance' => (string) ($node['normal_balance'] ?? 'debit'),
                    'parent_account_id' => $parent?->id,
                    'level' => $level,
                    'hierarchy_path' => $hierarchyPath,
                    'vat_code_id' => $vatCodeId,
                    'open_items_enabled' => (bool) ($node['open_items_enabled'] ?? false),
                    'allowed_currencies' => $node['allowed_currencies'] ?? null,
                    'is_postable' => $isPostable,
                    'allows_manual_posting' => $isPostable,
                    'is_system' => true,
                    'is_active' => true,
                    'template_code' => $command->templateKey,
                    'statutory_code' => $node['statutory_code'] ?? null,
                    'reporting_class' => $node['reporting_class'] ?? null,
                    'is_reconciliation_account' => $reconciliationType !== null,
                    'reconciliation_type' => $reconciliationType,
                ]);

                $existingAccounts->put($code, $account);
                $created[] = $account;
                $createdCount++;
            }

            $mappingsCreated = 0;

            if ($command->includeDefaultMappings && $defaultMappings !== []) {
                foreach ($defaultMappings as $mapping) {
                    $sourceCode = (string) ($mapping['source_code'] ?? '');
                    $targetCode = (string) ($mapping['target_code'] ?? '');
                    $targetStandard = (string) ($mapping['target_standard'] ?? '');
                    $sourceAccount = $existingAccounts->get($sourceCode);

                    if (! $sourceAccount instanceof ChartOfAccount || $targetCode === '' || $targetStandard === '') {
                        continue;
                    }

                    $exists = AccountMapping::withoutGlobalScopes()
                        ->where('tenant_id', $command->tenantId)
                        ->where('source_account_id', $sourceAccount->id)
                        ->where('target_standard', $targetStandard)
                        ->where('target_account_code', $targetCode)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $targetAccount = $existingAccounts->get($targetCode);

                    AccountMapping::create([
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $command->tenantId,
                        'company_id' => $command->companyId,
                        'source_account_id' => $sourceAccount->id,
                        'target_account_id' => $targetAccount?->id,
                        'source_standard' => $standard,
                        'target_standard' => $targetStandard,
                        'target_account_code' => $targetCode,
                        'target_account_name' => (string) ($mapping['target_name'] ?? $targetCode),
                        'mapping_type' => (string) ($mapping['mapping_type'] ?? 'direct'),
                        'is_bidirectional' => (bool) ($mapping['is_bidirectional'] ?? true),
                        'allocation_percentage' => (string) ($mapping['allocation_percentage'] ?? '100.0000'),
                        'notes' => $mapping['notes'] ?? null,
                        'is_active' => true,
                    ]);

                    $mappingsCreated++;
                }
            }

            return [
                'template' => $command->templateKey,
                'created_count' => $createdCount,
                'skipped_count' => $skippedCount,
                'mappings_created' => $mappingsCreated,
                'accounts' => $created,
            ];
        });
    }
}
