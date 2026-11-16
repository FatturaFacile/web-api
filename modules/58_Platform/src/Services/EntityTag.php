<?php

declare(strict_types=1);

namespace Modules\Platform\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * The entity tag of a record: a weak validator derived from its identity, its version column (when it has one) and its
 * updated_at timestamp, so any saved change produces a new tag.
 */
final class EntityTag
{
    public function for(Model $record): string
    {
        $version = $record->getAttribute('version');
        $updatedAt = $record->getAttribute('updated_at');
        $stamp = $updatedAt instanceof \DateTimeInterface ? $updatedAt->format('Uu') : (string) $updatedAt;

        return 'W/"'.substr(hash('sha256', implode('|', [$record::class, (string) $record->getKey(), (string) $version, $stamp])), 0, 32).'"';
    }

    /**
     * Whether a header value (a comma separated list of tags, or *) names the current tag of the record.
     */
    public function matches(string $headerValue, Model $record, bool $allowWildcard = true): bool
    {
        $current = $this->normalise($this->for($record));

        foreach (explode(',', $headerValue) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '*' && $allowWildcard) {
                return true;
            }

            if ($candidate !== '' && $this->normalise($candidate) === $current) {
                return true;
            }
        }

        return false;
    }

    private function normalise(string $tag): string
    {
        return trim(preg_replace('/^W\//', '', trim($tag)) ?? '', '"');
    }
}
