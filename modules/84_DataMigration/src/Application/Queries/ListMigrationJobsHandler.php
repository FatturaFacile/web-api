<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Queries;

use Illuminate\Database\Eloquent\Collection;
use Modules\DataMigration\Models\MigrationJob;

final readonly class ListMigrationJobsHandler
{
    /**
     * @return Collection<int, MigrationJob>
     */
    public function handle(ListMigrationJobsQuery $query): Collection
    {
        return MigrationJob::query()
            ->when($query->status !== null, static fn ($q) => $q->where('status', $query->status))
            ->when($query->sourceSystem !== null, static fn ($q) => $q->where('source_system', $query->sourceSystem))
            ->when($query->legalEntityId !== null, static fn ($q) => $q->where('legal_entity_id', $query->legalEntityId))
            ->latest('created_at')
            ->limit($query->limit)
            ->get();
    }
}
