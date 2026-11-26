<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Queries;

use Illuminate\Database\Eloquent\Collection;
use Modules\DataMigration\Models\MigrationFieldMappingTemplate;

final readonly class ListMigrationTemplatesHandler
{
    /**
     * @return Collection<int, MigrationFieldMappingTemplate>
     */
    public function handle(ListMigrationTemplatesQuery $query): Collection
    {
        return MigrationFieldMappingTemplate::query()
            ->when($query->sourceSystem !== null, static fn ($q) => $q->where('source_system', $query->sourceSystem))
            ->when($query->entityType !== null, static fn ($q) => $q->where('entity_type', $query->entityType))
            ->orderByDesc('is_system_preset')
            ->orderBy('name')
            ->get();
    }
}
