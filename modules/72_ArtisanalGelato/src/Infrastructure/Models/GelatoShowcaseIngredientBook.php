<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Published, versioned snapshot of the showcase and allergen book.
 */
final class GelatoShowcaseIngredientBook extends BaseEnterpriseModel
{
    protected $table = 'gelato_showcase_ingredient_books';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'payload' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
