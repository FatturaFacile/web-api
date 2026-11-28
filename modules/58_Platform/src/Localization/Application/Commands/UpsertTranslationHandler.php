<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Commands;

use Modules\Platform\Localization\Domain\Contracts\TranslationCatalogRepository;
use Modules\Platform\Localization\Domain\Entities\TranslationEntry;
use Modules\Platform\Localization\Domain\Exceptions\LockedTranslationException;

final class UpsertTranslationHandler
{
    public function __construct(private readonly TranslationCatalogRepository $catalogs) {}

    /**
     * @throws LockedTranslationException when the key is a locked system key
     */
    public function __invoke(UpsertTranslationCommand $command): TranslationEntry
    {
        $catalog = $this->catalogs->load($command->tenantId, $command->locale, [$command->key]);
        $entry = $catalog->upsert($command->key, $command->value);
        $this->catalogs->save($catalog);

        return $entry;
    }
}
