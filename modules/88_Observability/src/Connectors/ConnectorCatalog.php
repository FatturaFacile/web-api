<?php

declare(strict_types=1);

namespace Modules\Observability\Connectors;

/**
 * The institutional services that are probed (config observability.connectors.catalog).
 */
final class ConnectorCatalog
{
    /**
     * @return array<string, array{key: string, name_code: string, country: string, group: string, url: string, expected: list<int>}>
     */
    public function all(): array
    {
        $catalog = [];

        foreach ((array) config('observability.connectors.catalog', []) as $key => $entry) {
            $key = (string) $key;
            $catalog[$key] = [
                'key' => $key,
                'name_code' => 'OBSERVABILITY.CONNECTOR.'.strtoupper($key),
                'country' => (string) ($entry['country'] ?? 'EU'),
                'group' => (string) ($entry['group'] ?? 'fiscal'),
                'url' => (string) ($entry['url'] ?? ''),
                'expected' => array_values(array_map(intval(...), (array) ($entry['expected'] ?? [200]))),
            ];
        }

        return $catalog;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /**
     * @return array{key: string, name_code: string, country: string, group: string, url: string, expected: list<int>}|null
     */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }
}
