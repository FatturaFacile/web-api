<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Queries;

use Modules\Platform\Localization\Infrastructure\Persistence\TranslationReadModel;

final class ListTranslationsHandler
{
    public function __construct(private readonly TranslationReadModel $readModel) {}

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, current_page: int, per_page: int, last_page: int, missing_count: int, groups: list<string>}
     */
    public function __invoke(ListTranslationsQuery $query): array
    {
        return $this->readModel->page($query->tenantId, $query->locale, $query->group, $query->onlyMissing, $query->search, $query->page, $query->perPage)
            + ['groups' => $this->readModel->groups($query->tenantId)];
    }
}
