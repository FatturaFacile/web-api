<?php

declare(strict_types=1);

namespace Modules\Childcare\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An observation about a child on a day. The ones visible to the family make up the daily report.
 *
 * @property string $child_id
 * @property string $body
 * @property bool $visible_to_family
 */
final class Note extends ChildcareRecord
{
    /** @var list<string> */
    public const array Categories = ['general', 'meal', 'sleep', 'activity', 'health', 'behaviour'];

    protected $table = 'cc_notes';

    /** @var list<string> */
    protected $hidden = ['child'];

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'body' => 'encrypted', 'visible_to_family' => 'boolean'];
    }

    /**
     * @return BelongsTo<Child, $this>
     */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class, 'child_id');
    }

    /**
     * Name of the child, computed in PHP because the names are encrypted at rest.
     *
     * @return Attribute<?string, never>
     */
    protected function childName(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->child?->display_name);
    }

    /**
     * The decrypted body under a name the grid can sort and filter in PHP (the `body` column holds the ciphertext).
     *
     * @return Attribute<string, never>
     */
    protected function bodyText(): Attribute
    {
        return Attribute::get(fn (): string => (string) $this->body);
    }
}
