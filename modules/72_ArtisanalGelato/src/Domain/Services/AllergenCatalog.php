<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Domain\Services;

/**
 * The 14 allergens of Annex II of EU Regulation 1169/2011 (Italy), which the Swiss OIDAI (Annex 6) lists identically, with their
 * legal names in the four languages of the platform. Legal reference data: the codes are stored on the ingredients, the names are
 * what the allergen book prints.
 */
final class AllergenCatalog
{
    /** @var array<string, array{it: string, en: string, de: string, fr: string}> */
    private const array ALLERGENS = [
        'gluten' => ['it' => 'Cereali contenenti glutine', 'en' => 'Cereals containing gluten', 'de' => 'Glutenhaltiges Getreide', 'fr' => 'Céréales contenant du gluten'],
        'crustaceans' => ['it' => 'Crostacei', 'en' => 'Crustaceans', 'de' => 'Krebstiere', 'fr' => 'Crustacés'],
        'eggs' => ['it' => 'Uova', 'en' => 'Eggs', 'de' => 'Eier', 'fr' => 'Œufs'],
        'fish' => ['it' => 'Pesce', 'en' => 'Fish', 'de' => 'Fisch', 'fr' => 'Poissons'],
        'peanuts' => ['it' => 'Arachidi', 'en' => 'Peanuts', 'de' => 'Erdnüsse', 'fr' => 'Arachides'],
        'soy' => ['it' => 'Soia', 'en' => 'Soy', 'de' => 'Soja', 'fr' => 'Soja'],
        'milk' => ['it' => 'Latte e lattosio', 'en' => 'Milk and lactose', 'de' => 'Milch und Laktose', 'fr' => 'Lait et lactose'],
        'nuts' => ['it' => 'Frutta a guscio', 'en' => 'Tree nuts', 'de' => 'Schalenfrüchte', 'fr' => 'Fruits à coque'],
        'celery' => ['it' => 'Sedano', 'en' => 'Celery', 'de' => 'Sellerie', 'fr' => 'Céleri'],
        'mustard' => ['it' => 'Senape', 'en' => 'Mustard', 'de' => 'Senf', 'fr' => 'Moutarde'],
        'sesame' => ['it' => 'Semi di sesamo', 'en' => 'Sesame seeds', 'de' => 'Sesamsamen', 'fr' => 'Graines de sésame'],
        'sulphites' => ['it' => 'Anidride solforosa e solfiti', 'en' => 'Sulphur dioxide and sulphites', 'de' => 'Schwefeldioxid und Sulfite', 'fr' => 'Anhydride sulfureux et sulfites'],
        'lupin' => ['it' => 'Lupini', 'en' => 'Lupin', 'de' => 'Lupinen', 'fr' => 'Lupin'],
        'molluscs' => ['it' => 'Molluschi', 'en' => 'Molluscs', 'de' => 'Weichtiere', 'fr' => 'Mollusques'],
    ];

    /** @var array<string, string> Mandatory notice of a product made from frozen-then-thawed material. */
    private const array DEFROSTED = ['it' => 'Decongelato', 'en' => 'Defrosted', 'de' => 'Aufgetaut', 'fr' => 'Décongelé'];

    public static function defrostedNotice(string $locale): string
    {
        return self::DEFROSTED[$locale] ?? self::DEFROSTED['en'];
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::ALLERGENS);
    }

    /**
     * @return array<string, string> code => name in the locale
     */
    public static function names(string $locale): array
    {
        $locale = in_array($locale, ['it', 'en', 'de', 'fr'], true) ? $locale : 'en';

        return array_map(static fn (array $names): string => $names[$locale], self::ALLERGENS);
    }
}
