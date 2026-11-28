<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Data;

/**
 * The dictionary of one locale: what the clients download and cache by version hash.
 */
final readonly class DictionaryData
{
    /**
     * @param  array<string, string>  $entries
     */
    public function __construct(public string $locale, public string $versionHash, public array $entries) {}

    /**
     * The value of the ETag header: the version hash in quotes.
     */
    public function etag(): string
    {
        return '"'.$this->versionHash.'"';
    }

    /**
     * Whether an If-None-Match header (a list of entity tags, possibly weak) names this version.
     */
    public function matches(?string $ifNoneMatch): bool
    {
        if ($ifNoneMatch === null || trim($ifNoneMatch) === '') {
            return false;
        }

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            $candidate = str_starts_with($candidate, 'W/') ? substr($candidate, 2) : $candidate;

            if (trim($candidate, '"') === $this->versionHash) {
                return true;
            }
        }

        return false;
    }
}
