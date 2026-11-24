<?php

declare(strict_types=1);

namespace Modules\Collaboration\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Collaboration\Models\CollabTag;
use Modules\Collaboration\Models\CollabTaggable;

/**
 * Attaches the shared tags of the company to records.
 */
final class TagService
{
    /**
     * @return Collection<int, CollabTag>
     */
    public function tagsOf(RecordType $type, Model $record): Collection
    {
        $tagIds = CollabTaggable::query()
            ->where('taggable_type', $type->key)
            ->where('taggable_id', (string) $record->getKey())
            ->pluck('tag_id');

        return CollabTag::query()->whereIn('id', $tagIds)->orderBy('name')->get();
    }

    /**
     * Attaching a tag that is already attached keeps the single link.
     */
    public function attach(RecordType $type, Model $record, CollabTag $tag): void
    {
        CollabTaggable::query()->firstOrCreate([
            'tag_id' => $tag->id,
            'taggable_type' => $type->key,
            'taggable_id' => (string) $record->getKey(),
        ], ['tenant_id' => (string) $record->getAttribute('tenant_id')]);
    }

    public function detach(RecordType $type, Model $record, CollabTag $tag): void
    {
        CollabTaggable::query()
            ->where('tag_id', $tag->id)
            ->where('taggable_type', $type->key)
            ->where('taggable_id', (string) $record->getKey())
            ->get()
            ->each(static fn (CollabTaggable $link): ?bool => $link->forceDelete());
    }

    /**
     * Deleting a tag removes its links for good.
     */
    public function delete(CollabTag $tag): void
    {
        $tag->taggables()->get()->each(static fn (CollabTaggable $link): ?bool => $link->forceDelete());
        $tag->delete();
    }
}
