<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Application\Commands;

/**
 * Sets the flavors of the showcase wells of a location and publishes the versioned multi-language ingredient and allergen book.
 */
final readonly class PublishActiveShowcaseFlavorsAndIngredientBookCommand
{
    /**
     * @param  list<array{slot_number: int, recipe_id: string}>  $slots
     */
    public function __construct(
        public string $locationKey,
        public array $slots,
        public string $jurisdiction,
        public ?string $publishedBy,
    ) {}
}
