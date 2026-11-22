<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Line of a recipe: grams of an ingredient or of a sub-recipe.
 */
final class GelatoRecipeLine extends BaseEnterpriseModel
{
    protected $table = 'gelato_recipe_lines';

    protected function casts(): array
    {
        return [
            'grams' => 'float',
            'position' => 'integer',
        ];
    }
}
