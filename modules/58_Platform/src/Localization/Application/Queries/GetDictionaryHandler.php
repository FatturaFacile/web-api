<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Queries;

use Modules\Platform\Localization\Application\Data\DictionaryData;
use Modules\Platform\Localization\Infrastructure\Cache\DictionaryCache;
use Modules\Platform\Localization\Infrastructure\Persistence\DictionaryBuilder;

final class GetDictionaryHandler
{
    public function __construct(private readonly DictionaryCache $cache, private readonly DictionaryBuilder $builder) {}

    public function __invoke(GetDictionaryQuery $query): DictionaryData
    {
        $payload = $this->cache->remember($query->tenantId, $query->locale->value(), fn (): array => $this->builder->build($query->tenantId, $query->locale));

        return new DictionaryData($query->locale->value(), $payload['version_hash'], $payload['entries']);
    }
}
