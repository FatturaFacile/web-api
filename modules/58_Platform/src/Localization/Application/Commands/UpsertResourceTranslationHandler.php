<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Commands;

use Illuminate\Support\Facades\DB;
use Modules\Platform\Localization\Domain\ValueObjects\Locale;
use Modules\Platform\Localization\Infrastructure\Persistence\ResourceTranslationRepository;

final class UpsertResourceTranslationHandler
{
    public function __construct(private readonly ResourceTranslationRepository $repository) {}

    public function __invoke(UpsertResourceTranslationCommand $command): void
    {
        $this->repository->record($command->resource, $command->id, $command->tenantId);

        DB::transaction(function () use ($command): void {
            foreach ($command->translations as $locale => $values) {
                $this->repository->save($command->resource, $command->id, $command->tenantId, Locale::from($locale)->value(), array_intersect_key($values, array_flip($command->resource->fields())));
            }
        });
    }
}
