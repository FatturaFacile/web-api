<?php

declare(strict_types=1);

namespace Modules\Marketplace\Support;

use Modules\Marketplace\Contracts\RecordMapper;

/**
 * The mappers of the pulled records. Other modules plug theirs in by resolving the registry and calling register().
 */
final class RecordMapperRegistry
{
    /** @var list<RecordMapper> */
    private array $mappers = [];

    public function register(RecordMapper $mapper): void
    {
        $this->mappers[] = $mapper;
    }

    public function find(string $connectorKey, string $resource): ?RecordMapper
    {
        foreach ($this->mappers as $mapper) {
            if ($mapper->supports($connectorKey, $resource)) {
                return $mapper;
            }
        }

        return null;
    }
}
