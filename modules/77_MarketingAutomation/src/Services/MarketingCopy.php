<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services;

use Modules\MarketingAutomation\Models\MarketingBlueprint;

/**
 * Reads every piece of copy (footers, notices, unsubscribe pages) from the seeded blueprints of kind `text`.
 * The database is the single source of truth: there are no language files and no hard-coded fallbacks for Mod 155.
 */
final class MarketingCopy
{
    /** @var array<string, array<string, string>>|null */
    private ?array $cache = null;

    /**
     * @param  array<string, string>  $vars
     */
    public function get(string $key, string $locale, array $vars = []): string
    {
        $entry = $this->all()[$key] ?? [];
        $text = $entry[$locale] ?? $entry['it'] ?? $entry['en'] ?? '';

        foreach ($vars as $name => $value) {
            $text = str_replace('{{'.$name.'}}', $value, $text);
        }

        return $text;
    }

    public function flush(): void
    {
        $this->cache = null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $this->cache = [];
        foreach (MarketingBlueprint::withoutGlobalScopes()->where('kind', 'text')->where('is_active', true)->get() as $row) {
            foreach ((array) $row->texts as $locale => $bag) {
                $this->cache[$row->code][$locale] = (string) ($bag['value'] ?? '');
            }
        }

        return $this->cache;
    }
}
