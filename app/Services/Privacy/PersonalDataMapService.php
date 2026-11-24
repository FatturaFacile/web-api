<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The map of the personal data held by a company (record of processing support, GDPR art. 30): every registered table
 * with its personal columns, retention class, the subjects it belongs to and how many rows the company holds.
 */
final readonly class PersonalDataMapService
{
    public function __construct(private PersonalDataRegistry $registry, private PersonalDataDiscovery $discovery) {}

    /**
     * @return array{tables: list<array<string, mixed>>, linked_records: list<array<string, mixed>>, exemptions: array<string, string>, unregistered_tables: array<string, list<string>>|null, generated_at: string}
     */
    public function build(string $tenantId, bool $scanUnregistered = false): array
    {
        $tables = [];
        foreach ($this->registry->entries() as $entry) {
            if (! Schema::hasTable($entry->table)) {
                continue;
            }

            $declared = $entry->personalColumns;
            $present = array_values(array_filter($declared, static fn (string $column): bool => Schema::hasColumn($entry->table, $column)));

            $tables[] = [
                'table' => $entry->table,
                'label' => $entry->label,
                'category' => $entry->category,
                'models' => $entry->models,
                'personal_columns' => $present,
                'undeclared_personal_columns' => array_values(array_diff($this->discovery->personalColumnsOf($entry->table), $declared)),
                'subject_types' => array_keys($entry->subjectLinks),
                'retention' => $entry->retention->value,
                'retention_note' => $entry->retentionNote,
                'retention_policy_available' => $entry->supportsRetentionPolicy(),
                'rows' => $this->countRows($entry, $tenantId),
            ];
        }

        $linked = [];
        foreach ($this->registry->linkedRecords() as $table => $link) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $linked[] = [
                'table' => $table,
                'subject_types' => array_keys($link['links']),
                'retention' => $link['retention']->value,
                'retention_note' => $link['note'],
            ];
        }

        return [
            'tables' => $tables,
            'linked_records' => $linked,
            'exemptions' => $this->registry->exemptions(),
            'unregistered_tables' => $scanUnregistered ? $this->discovery->unregisteredTables($this->registry) : null,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function countRows(PersonalDataEntry $entry, string $tenantId): int
    {
        $query = DB::table($entry->table);

        if ($entry->hasTenantColumn) {
            return $query->where('tenant_id', $tenantId)->count();
        }

        // Tables without a tenant column belong to a company through their parent (contact addresses, user accounts).
        if ($entry->tenantParent !== null) {
            $parent = $entry->tenantParent;

            return $query->whereIn($parent['column'], DB::table($parent['table'])->where('tenant_id', $tenantId)->select($parent['parentColumn']))->count();
        }

        return 0;
    }
}
