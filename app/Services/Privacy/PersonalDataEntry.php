<?php

declare(strict_types=1);

namespace App\Services\Privacy;

/**
 * One table of the personal data registry: which columns hold personal data, which kind of data subject the rows
 * belong to, and how long and under which rule they are kept.
 */
final readonly class PersonalDataEntry
{
    /**
     * @param  list<class-string>  $models  Eloquent models that map the table
     * @param  list<string>  $personalColumns  columns holding personal data
     * @param  array<string, string>  $subjectLinks  subject type => column of the row that identifies the subject ('id' for the subject table itself)
     * @param  string|null  $ageColumn  timestamp column a retention policy measures the age of a row on; null when the table cannot be aged out
     * @param  array{column: string, table: string, parentColumn: string}|null  $tenantParent  how a table without tenant_id belongs to a company: column IN (SELECT parentColumn FROM table WHERE tenant_id = ?)
     */
    public function __construct(
        public string $table,
        public array $models,
        public string $label,
        public string $category,
        public array $personalColumns,
        public PersonalDataRetention $retention,
        public string $retentionNote,
        public array $subjectLinks = [],
        public ?string $ageColumn = null,
        public bool $hasTenantColumn = true,
        public ?array $tenantParent = null,
    ) {}

    /**
     * Registry key of the entry: the table name.
     */
    public function key(): string
    {
        return $this->table;
    }

    /**
     * True when a retention policy may anonymise the rows of this table by age.
     */
    public function supportsRetentionPolicy(): bool
    {
        return $this->retention->isErasable() && $this->ageColumn !== null && $this->personalColumns !== [];
    }
}
