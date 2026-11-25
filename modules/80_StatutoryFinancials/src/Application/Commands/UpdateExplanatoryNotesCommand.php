<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

final readonly class UpdateExplanatoryNotesCommand
{
    /**
     * @param  array<string, array<string, mixed>>|null  $tables  Edited tables by name (each is stored as a manual table).
     * @param  list<array{key: string, title?: string, body: string}>|null  $sections  Edited narrative sections.
     */
    public function __construct(public string $dossierId, public ?array $tables, public ?array $sections, public ?string $userId = null) {}
}
