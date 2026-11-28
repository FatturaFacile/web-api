<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Data;

/**
 * Outcome (or dry-run preview) of a translation import: one row per imported text with the action that applies or applied.
 */
final readonly class ImportResult
{
    public const string CREATE = 'create';

    public const string UPDATE = 'update';

    public const string UNCHANGED = 'unchanged';

    public const string REJECTED = 'rejected';

    public const string BLOCKED = 'blocked';

    /**
     * @param  list<array{row: int, key: string, locale: string, action: string, old_value: string|null, new_value: string, errors: list<string>}>  $rows
     * @param  list<string>  $headerErrors
     */
    public function __construct(public bool $dryRun, public array $rows, public array $headerErrors = []) {}

    /**
     * @return array{created: int, updated: int, unchanged: int, rejected: int, blocked: int}
     */
    public function summary(): array
    {
        $count = fn (string $action): int => count(array_filter($this->rows, static fn (array $row): bool => $row['action'] === $action));

        return [
            'created' => $count(self::CREATE),
            'updated' => $count(self::UPDATE),
            'unchanged' => $count(self::UNCHANGED),
            'rejected' => $count(self::REJECTED) + ($this->headerErrors === [] ? 0 : 1),
            'blocked' => $count(self::BLOCKED),
        ];
    }

    public function hasErrors(): bool
    {
        return $this->headerErrors !== [] || $this->summary()['rejected'] > 0;
    }

    /**
     * @return list<string>
     */
    public function blockedKeys(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $row): string => $row['key'],
            array_filter($this->rows, static fn (array $row): bool => $row['action'] === self::BLOCKED),
        )));
    }

    /**
     * @return array{dry_run: bool, summary: array<string, int>, has_errors: bool, header_errors: list<string>, rows: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'summary' => $this->summary(),
            'has_errors' => $this->hasErrors(),
            'header_errors' => $this->headerErrors,
            'rows' => $this->rows,
        ];
    }
}
