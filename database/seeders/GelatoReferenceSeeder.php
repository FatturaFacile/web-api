<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoTechnicalIngredient;

/**
 * Global catalog of technical ingredients of the artisanal gelato, sorbet and chocolaterie vertical (Mod 398): more than 200
 * deterministic, idempotent rows (keyed by code) with POD and PAC (sucrose = 100 per 100 g), milk fat, other fat, SLNG, total
 * solids, sugar and solute class, fibre, protein, kcal, cost per kg, °Brix or cocoa breakdown and the EU 1169/2011 / OIDAI
 * allergens. Values are laboratory reference values of typical products; a tenant overrides them by adding its own ingredient.
 *
 * Row format: code, 'it|en|de|fr', category, [pod, pac, milk_fat, other_fat, slng, total_solids], [sugar, solute_class],
 * [fibre, protein, kcal, cost_per_kg], 'allergens', flags ('n' not vegan, 'f' frozen/defrosted), extras (brix, cocoa_butter,
 * cocoa_mass).
 */
final class GelatoReferenceSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->rows() as $row) {
            [$code, $names, $category, $idx, $sugar, $nutri, $allergens, $flags, $extra] = $row + [6 => '', 7 => '', 8 => []];
            [$it, $en, $de, $fr] = explode('|', $names);
            [$pod, $pac, $milkFat, $otherFat, $slng, $solids] = $idx;

            GelatoTechnicalIngredient::updateOrCreateGlobal(['code' => $code], [
                'name' => ['it' => $it, 'en' => $en, 'de' => $de, 'fr' => $fr],
                'category' => $category,
                'pod' => $pod,
                'pac' => $pac,
                'milk_fat_percent' => $milkFat,
                'other_fat_percent' => $otherFat,
                'slng_percent' => $slng,
                'total_solids_percent' => $solids,
                'water_percent' => max(0, 100 - $solids),
                'sugar_percent' => $sugar[0],
                'solute_class' => $sugar[1],
                'fiber_percent' => $nutri[0],
                'protein_percent' => $nutri[1],
                'kcal_per_100g' => $nutri[2],
                'cost_per_kg' => $nutri[3],
                'brix' => $extra['brix'] ?? null,
                'cocoa_butter_percent' => $extra['cocoa_butter'] ?? null,
                'cocoa_mass_percent' => $extra['cocoa_mass'] ?? null,
                'allergens' => $allergens === '' ? [] : explode(',', $allergens),
                'is_vegan' => ! str_contains($flags, 'n'),
                'is_defrosted' => str_contains($flags, 'f'),
                'is_active' => true,
            ]);
        }
    }

    /**
     * @return iterable<array<int, mixed>>
     */
    private function rows(): iterable
    {
        yield from $this->dairyAndEggs();
        yield from $this->sweeteners();
        yield from $this->stabilizers();
        yield from $this->nutsAndSeeds();
        yield from $this->chocolate();
        yield from $this->flavorings();
        yield from $this->fruits();
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function dairyAndEggs(): array
    {
        $none = [0, 'none'];
        $lactose = [4.9, 'none'];

        return [
            ['MILK_WHOLE', 'Latte intero|Whole milk|Vollmilch|Lait entier', 'DAIRY', [0.8, 0, 3.6, 0, 8.6, 12.2], $lactose, [0, 3.3, 64, 1.3], 'milk', 'n'],
            ['MILK_SKIM', 'Latte scremato|Skim milk|Magermilch|Lait écrémé', 'DAIRY', [0.8, 0, 0.1, 0, 9.0, 9.1], $lactose, [0, 3.4, 35, 1.2], 'milk', 'n'],
            ['MILK_PARTLY', 'Latte parzialmente scremato|Semi-skimmed milk|Teilentrahmte Milch|Lait demi-écrémé', 'DAIRY', [0.8, 0, 1.6, 0, 8.9, 10.5], $lactose, [0, 3.4, 46, 1.2], 'milk', 'n'],
            ['MILK_HIGH_FAT', 'Latte alta qualità 3,8%|High-quality milk 3.8%|Hochwertige Milch 3,8%|Lait de qualité 3,8%', 'DAIRY', [0.8, 0, 3.8, 0, 8.6, 12.4], $lactose, [0, 3.3, 65, 1.5], 'milk', 'n'],
            ['MILK_GOAT', 'Latte di capra|Goat milk|Ziegenmilch|Lait de chèvre', 'DAIRY', [0.8, 0, 3.8, 0, 8.4, 12.2], $lactose, [0, 3.4, 69, 2.6], 'milk', 'n'],
            ['MILK_SHEEP', 'Latte di pecora|Sheep milk|Schafmilch|Lait de brebis', 'DAIRY', [0.9, 0, 6.5, 0, 10.5, 17.0], [5.1, 'none'], [0, 5.5, 100, 2.9], 'milk', 'n'],
            ['MILK_BUFFALO', 'Latte di bufala|Buffalo milk|Büffelmilch|Lait de bufflonne', 'DAIRY', [0.8, 0, 8.0, 0, 9.2, 17.2], [4.7, 'none'], [0, 4.5, 97, 2.4], 'milk', 'n'],
            ['CREAM_20', 'Panna 20%|Cream 20%|Rahm 20%|Crème 20%', 'DAIRY', [0.6, 0, 20, 0, 7.3, 27.3], [3.8, 'none'], [0, 2.7, 205, 2.9], 'milk', 'n'],
            ['CREAM_35', 'Panna fresca 35%|Fresh cream 35%|Frischer Rahm 35%|Crème fraîche 35%', 'DAIRY', [0.5, 0, 35, 0, 5.6, 40.6], [3.0, 'none'], [0, 2.2, 340, 4.2], 'milk', 'n'],
            ['CREAM_38', 'Panna fresca 38%|Fresh cream 38%|Frischer Rahm 38%|Crème fraîche 38%', 'DAIRY', [0.5, 0, 38, 0, 5.4, 43.4], [2.9, 'none'], [0, 2.1, 363, 4.6], 'milk', 'n'],
            ['CREAM_UHT_35', 'Panna UHT 35%|UHT cream 35%|UHT-Rahm 35%|Crème UHT 35%', 'DAIRY', [0.5, 0, 35, 0, 5.6, 40.6], [3.0, 'none'], [0, 2.2, 340, 3.4], 'milk', 'n'],
            ['SMP', 'Latte scremato in polvere|Skim milk powder|Magermilchpulver|Lait écrémé en poudre', 'DAIRY', [8, 0, 0.8, 0, 95, 96], [50, 'none'], [0, 35, 360, 6.5], 'milk', 'n'],
            ['WMP', 'Latte intero in polvere|Whole milk powder|Vollmilchpulver|Lait entier en poudre', 'DAIRY', [6, 0, 26, 0, 70, 97], [38, 'none'], [0, 26, 500, 7.5], 'milk', 'n'],
            ['BUTTERMILK_POWDER', 'Latticello in polvere|Buttermilk powder|Buttermilchpulver|Babeurre en poudre', 'DAIRY', [7, 0, 5, 0, 86, 96], [47, 'none'], [0, 33, 390, 6.8], 'milk', 'n'],
            ['WHEY_POWDER', 'Siero di latte in polvere|Whey powder|Molkenpulver|Lactosérum en poudre', 'DAIRY', [10, 0, 1, 0, 94, 96], [70, 'none'], [0, 12, 350, 2.4], 'milk', 'n'],
            ['BUTTER', 'Burro|Butter|Butter|Beurre', 'DAIRY', [0, 0, 82, 0, 1.2, 84], $lactose, [0, 0.8, 740, 8.5], 'milk', 'n'],
            ['BUTTER_CLARIFIED', 'Burro chiarificato|Clarified butter|Butterreinfett|Beurre clarifié', 'DAIRY', [0, 0, 99, 0, 0.3, 99.5], $none, [0, 0.1, 890, 11.0], 'milk', 'n'],
            ['CONDENSED_MILK', 'Latte condensato zuccherato|Sweetened condensed milk|Gezuckerte Kondensmilch|Lait concentré sucré', 'DAIRY', [49, 55, 8.5, 0, 20.5, 73], [56, 'sucrose'], [0, 7.5, 330, 3.8], 'milk', 'n'],
            ['EVAPORATED_MILK', 'Latte evaporato|Evaporated milk|Kondensmilch|Lait évaporé', 'DAIRY', [1.7, 0, 7.5, 0, 17.5, 25], [9.7, 'none'], [0, 6.8, 134, 2.8], 'milk', 'n'],
            ['YOGURT_WHITE', 'Yogurt bianco intero|Plain whole yogurt|Naturjoghurt|Yaourt nature entier', 'DAIRY', [0.7, 0, 3.4, 0, 8.4, 12.0], [4.3, 'none'], [0, 3.8, 63, 2.1], 'milk', 'n'],
            ['YOGURT_GREEK', 'Yogurt greco 10%|Greek yogurt 10%|Griechischer Joghurt 10%|Yaourt grec 10%', 'DAIRY', [0.5, 0, 10, 0, 7.5, 17.5], [3.6, 'none'], [0, 5.5, 130, 3.9], 'milk', 'n'],
            ['MASCARPONE', 'Mascarpone|Mascarpone|Mascarpone|Mascarpone', 'DAIRY', [0.4, 0, 42, 0, 3.5, 46], [2.2, 'none'], [0, 4.0, 410, 7.5], 'milk', 'n'],
            ['RICOTTA', 'Ricotta vaccina|Cow ricotta|Kuh-Ricotta|Ricotta de vache', 'DAIRY', [0.3, 0, 11, 0, 7.5, 19.5], [3.5, 'none'], [0, 8.0, 150, 5.2], 'milk', 'n'],
            ['CREAM_CHEESE', 'Formaggio spalmabile|Cream cheese|Frischkäse|Fromage frais à tartiner', 'DAIRY', [0.4, 0, 24, 0, 7.5, 32], [3.0, 'none'], [0, 6.0, 255, 6.8], 'milk', 'n'],
            ['SOY_DRINK', 'Bevanda di soia|Soy drink|Sojadrink|Boisson au soja', 'PLANT_MILK', [1, 0, 0, 1.8, 0, 7.5], [2.5, 'none'], [0.5, 3.3, 40, 1.4], 'soy', ''],
            ['OAT_DRINK', 'Bevanda di avena|Oat drink|Haferdrink|Boisson à l\'avoine', 'PLANT_MILK', [4, 8, 0, 1.5, 0, 12], [4.5, 'glucose'], [0.8, 1.0, 46, 1.6], 'gluten', ''],
            ['ALMOND_DRINK', 'Bevanda di mandorla|Almond drink|Mandeldrink|Boisson aux amandes', 'PLANT_MILK', [1, 0, 0, 1.1, 0, 4.5], [3.0, 'none'], [0.3, 0.5, 24, 2.2], 'nuts', ''],
            ['RICE_DRINK', 'Bevanda di riso|Rice drink|Reisdrink|Boisson au riz', 'PLANT_MILK', [6, 12, 0, 1.0, 0, 13], [7.0, 'glucose'], [0.2, 0.3, 47, 1.7], '', ''],
            ['COCONUT_MILK', 'Latte di cocco|Coconut milk|Kokosmilch|Lait de coco', 'PLANT_MILK', [1, 0, 0, 17, 0, 22], [2.8, 'none'], [0, 1.8, 180, 3.1], '', ''],
            ['COCONUT_CREAM', 'Crema di cocco|Coconut cream|Kokoscreme|Crème de coco', 'PLANT_MILK', [1, 0, 0, 24, 0, 31], [3.0, 'none'], [0, 2.3, 230, 4.0], '', ''],
            ['EGG_YOLK', 'Tuorlo d\'uovo pastorizzato|Pasteurized egg yolk|Pasteurisiertes Eigelb|Jaune d\'œuf pasteurisé', 'EGG', [0, 0, 0, 27, 0, 50], $none, [0, 16, 320, 6.2], 'eggs', 'n'],
            ['EGG_WHITE', 'Albume pastorizzato|Pasteurized egg white|Pasteurisiertes Eiweiss|Blanc d\'œuf pasteurisé', 'EGG', [0, 0, 0, 0, 0, 12.5], $none, [0, 10.9, 48, 3.4], 'eggs', 'n'],
            ['EGG_WHOLE', 'Uovo intero pastorizzato|Pasteurized whole egg|Pasteurisiertes Vollei|Œuf entier pasteurisé', 'EGG', [0, 0, 0, 10, 0, 25], $none, [0, 12.5, 143, 3.9], 'eggs', 'n'],
            ['EGG_YOLK_SUGARED', 'Tuorlo zuccherato 20%|Sugared egg yolk 20%|Gezuckertes Eigelb 20%|Jaune sucré 20%', 'EGG', [20, 20, 0, 21.5, 0, 60], [20, 'sucrose'], [0, 12, 300, 6.8], 'eggs', 'n'],
            ['EGG_POWDER', 'Uovo in polvere|Egg powder|Eipulver|Œuf en poudre', 'EGG', [0, 0, 0, 40, 0, 96], $none, [0, 47, 590, 18.0], 'eggs', 'n'],
            ['CUSTARD_BASE', 'Base crema pronta|Ready custard base|Fertige Cremebasis|Base crème prête', 'BASE', [10, 35, 4, 0, 6, 40], [25, 'sucrose'], [1, 4, 220, 8.5], 'milk,eggs', 'n'],
            ['BASE_NEUTRAL_HOT', 'Base neutra a caldo|Hot-process neutral base|Neutralbasis heiss|Base neutre à chaud', 'BASE', [22, 55, 0, 0, 36, 95], [58, 'sucrose'], [20, 6, 370, 9.0], 'milk', 'n'],
            ['BASE_NEUTRAL_COLD', 'Base neutra a freddo|Cold-process neutral base|Neutralbasis kalt|Base neutre à froid', 'BASE', [18, 45, 4, 2, 20, 94], [60, 'sucrose'], [10, 5, 390, 8.0], 'milk,soy', 'n'],
            ['BASE_FRUIT_SORBET', 'Base sorbetto|Sorbet base|Sorbetbasis|Base sorbet', 'BASE', [40, 100, 0, 0, 0, 96], [90, 'sucrose'], [5, 0, 380, 6.5], '', ''],
        ];
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function sweeteners(): array
    {
        return [
            ['SUCROSE', 'Saccarosio|Sucrose|Saccharose|Saccharose', 'SUGAR', [100, 100, 0, 0, 0, 100], [100, 'sucrose'], [0, 0, 400, 1.1], '', ''],
            ['SUGAR_BROWN', 'Zucchero di canna|Brown sugar|Rohrzucker|Sucre de canne', 'SUGAR', [95, 95, 0, 0, 0, 98], [95, 'sucrose'], [0, 0, 390, 2.0], '', ''],
            ['SUGAR_MUSCOVADO', 'Zucchero muscovado|Muscovado sugar|Muscovado-Zucker|Sucre muscovado', 'SUGAR', [90, 95, 0, 0, 0, 97], [88, 'sucrose'], [0, 0, 380, 3.8], '', ''],
            ['SUGAR_COCONUT', 'Zucchero di cocco|Coconut sugar|Kokosblütenzucker|Sucre de coco', 'SUGAR', [75, 90, 0, 0, 0, 97], [80, 'sucrose'], [0, 1, 375, 6.5], '', ''],
            ['SUGAR_ICING', 'Zucchero a velo|Icing sugar|Puderzucker|Sucre glace', 'SUGAR', [100, 100, 0, 0, 0, 99], [99, 'sucrose'], [0, 0, 395, 1.6], '', ''],
            ['DEXTROSE', 'Destrosio|Dextrose|Dextrose|Dextrose', 'SUGAR', [70, 190, 0, 0, 0, 92], [92, 'glucose'], [0, 0, 365, 2.1], '', ''],
            ['FRUCTOSE', 'Fruttosio|Fructose|Fruktose|Fructose', 'SUGAR', [170, 190, 0, 0, 0, 99], [99, 'invert'], [0, 0, 400, 3.2], '', ''],
            ['GLUCOSE_DE21', 'Sciroppo di glucosio disidratato DE 21|Dried glucose syrup DE 21|Glukosesirup getrocknet DE 21|Sirop de glucose déshydraté DE 21', 'SUGAR', [20, 35, 0, 0, 0, 96], [96, 'glucose'], [0, 0, 380, 2.4], '', ''],
            ['GLUCOSE_DE30', 'Sciroppo di glucosio disidratato DE 30|Dried glucose syrup DE 30|Glukosesirup getrocknet DE 30|Sirop de glucose déshydraté DE 30', 'SUGAR', [30, 55, 0, 0, 0, 96], [96, 'glucose'], [0, 0, 380, 2.4], '', ''],
            ['GLUCOSE_DE38', 'Sciroppo di glucosio disidratato DE 38|Dried glucose syrup DE 38|Glukosesirup getrocknet DE 38|Sirop de glucose déshydraté DE 38', 'SUGAR', [38, 70, 0, 0, 0, 96], [96, 'glucose'], [0, 0, 380, 2.5], '', ''],
            ['GLUCOSE_DE60', 'Sciroppo di glucosio disidratato DE 60|Dried glucose syrup DE 60|Glukosesirup getrocknet DE 60|Sirop de glucose déshydraté DE 60', 'SUGAR', [60, 115, 0, 0, 0, 96], [96, 'glucose'], [0, 0, 380, 2.7], '', ''],
            ['GLUCOSE_LIQ_DE30', 'Sciroppo di glucosio liquido DE 30|Liquid glucose syrup DE 30|Flüssiger Glukosesirup DE 30|Sirop de glucose liquide DE 30', 'SUGAR', [24, 44, 0, 0, 0, 80], [80, 'glucose'], [0, 0, 320, 1.8], '', ''],
            ['GLUCOSE_LIQ_DE42', 'Sciroppo di glucosio liquido DE 42|Liquid glucose syrup DE 42|Flüssiger Glukosesirup DE 42|Sirop de glucose liquide DE 42', 'SUGAR', [34, 62, 0, 0, 0, 80], [80, 'glucose'], [0, 0, 320, 1.8], '', ''],
            ['INVERT_PASTE', 'Zucchero invertito pasta|Invert sugar paste|Invertzuckercreme|Sucre inverti en pâte', 'SUGAR', [100, 140, 0, 0, 0, 75], [75, 'invert'], [0, 0, 300, 3.0], '', ''],
            ['INVERT_LIQUID', 'Zucchero invertito liquido|Liquid invert sugar|Flüssiger Invertzucker|Sucre inverti liquide', 'SUGAR', [95, 135, 0, 0, 0, 72], [72, 'invert'], [0, 0, 290, 2.8], '', ''],
            ['HONEY', 'Miele|Honey|Honig|Miel', 'SUGAR', [130, 190, 0, 0, 0, 80], [78, 'invert'], [0, 0.3, 304, 8.0], '', 'n'],
            ['HONEY_ACACIA', 'Miele di acacia|Acacia honey|Akazienhonig|Miel d\'acacia', 'SUGAR', [140, 195, 0, 0, 0, 80], [79, 'invert'], [0, 0.3, 304, 11.0], '', 'n'],
            ['MAPLE_SYRUP', 'Sciroppo d\'acero|Maple syrup|Ahornsirup|Sirop d\'érable', 'SUGAR', [100, 130, 0, 0, 0, 67], [66, 'sucrose'], [0, 0, 260, 14.0], '', ''],
            ['AGAVE_SYRUP', 'Sciroppo d\'agave|Agave syrup|Agavendicksaft|Sirop d\'agave', 'SUGAR', [140, 200, 0, 0, 0, 76], [75, 'invert'], [0, 0, 310, 8.5], '', ''],
            ['DATE_SYRUP', 'Sciroppo di datteri|Date syrup|Dattelsirup|Sirop de dattes', 'SUGAR', [75, 125, 0, 0, 0, 70], [68, 'invert'], [1, 1, 280, 9.0], '', ''],
            ['RICE_SYRUP', 'Sciroppo di riso|Rice syrup|Reissirup|Sirop de riz', 'SUGAR', [30, 60, 0, 0, 0, 80], [78, 'glucose'], [0, 0.3, 310, 5.5], '', ''],
            ['MOLASSES', 'Melassa|Molasses|Melasse|Mélasse', 'SUGAR', [70, 110, 0, 0, 0, 78], [55, 'sucrose'], [0, 1, 290, 4.0], '', ''],
            ['TREHALOSE', 'Trealosio|Trehalose|Trehalose|Tréhalose', 'SUGAR', [45, 100, 0, 0, 0, 98], [98, 'glucose'], [0, 0, 380, 7.0], '', ''],
            ['MALTODEXTRIN', 'Maltodestrina DE 15|Maltodextrin DE 15|Maltodextrin DE 15|Maltodextrine DE 15', 'SUGAR', [10, 20, 0, 0, 0, 96], [96, 'glucose'], [0, 0, 380, 2.8], '', ''],
            ['INULIN', 'Inulina|Inulin|Inulin|Inuline', 'FIBER', [10, 10, 0, 0, 0, 96], [5, 'none'], [90, 0, 150, 9.0], '', ''],
            ['POLYDEXTROSE', 'Polidestrosio|Polydextrose|Polydextrose|Polydextrose', 'FIBER', [5, 10, 0, 0, 0, 96], [2, 'none'], [90, 0, 100, 6.0], '', ''],
            ['ERYTHRITOL', 'Eritritolo|Erythritol|Erythrit|Érythritol', 'POLYOL', [70, 280, 0, 0, 0, 99], [0, 'sorbitol'], [0, 0, 20, 8.0], '', ''],
            ['SORBITOL', 'Sorbitolo|Sorbitol|Sorbit|Sorbitol', 'POLYOL', [50, 190, 0, 0, 0, 98], [98, 'sorbitol'], [0, 0, 240, 4.5], '', ''],
            ['XYLITOL', 'Xilitolo|Xylitol|Xylit|Xylitol', 'POLYOL', [100, 270, 0, 0, 0, 99], [0, 'sorbitol'], [0, 0, 240, 9.5], '', ''],
            ['MALTITOL', 'Maltitolo|Maltitol|Maltit|Maltitol', 'POLYOL', [90, 100, 0, 0, 0, 99], [0, 'sorbitol'], [0, 0, 240, 6.0], '', ''],
            ['ISOMALT', 'Isomalto|Isomalt|Isomalt|Isomalt', 'POLYOL', [50, 60, 0, 0, 0, 99], [0, 'sorbitol'], [0, 0, 200, 7.5], '', ''],
            ['GLYCEROL', 'Glicerolo|Glycerol|Glycerin|Glycérol', 'POLYOL', [60, 370, 0, 0, 0, 99], [0, 'sorbitol'], [0, 0, 400, 5.0], '', ''],
        ];
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function stabilizers(): array
    {
        $gum = [0, 'none'];

        return [
            ['LBG', 'Farina di semi di carrube|Locust bean gum|Johannisbrotkernmehl|Gomme de caroube', 'STABILIZER', [0, 0, 0, 0, 0, 90], $gum, [80, 5, 20, 22.0], '', ''],
            ['GUAR', 'Gomma di guar|Guar gum|Guarkernmehl|Gomme de guar', 'STABILIZER', [0, 0, 0, 0, 0, 90], $gum, [80, 5, 20, 12.0], '', ''],
            ['TARA', 'Gomma di tara|Tara gum|Tarakernmehl|Gomme de tara', 'STABILIZER', [0, 0, 0, 0, 0, 90], $gum, [80, 4, 20, 25.0], '', ''],
            ['XANTHAN', 'Gomma xantana|Xanthan gum|Xanthan|Gomme xanthane', 'STABILIZER', [0, 0, 0, 0, 0, 92], $gum, [80, 0, 10, 28.0], '', ''],
            ['PECTIN_HM', 'Pectina HM|HM pectin|HM-Pektin|Pectine HM', 'STABILIZER', [0, 0, 0, 0, 0, 92], $gum, [75, 2, 15, 30.0], '', ''],
            ['PECTIN_NH', 'Pectina NH|NH pectin|NH-Pektin|Pectine NH', 'STABILIZER', [0, 0, 0, 0, 0, 92], $gum, [75, 2, 15, 34.0], '', ''],
            ['AGAR', 'Agar-agar|Agar-agar|Agar-Agar|Agar-agar', 'STABILIZER', [0, 0, 0, 0, 0, 90], $gum, [75, 2, 15, 85.0], '', ''],
            ['CARRAGEENAN', 'Carragenina|Carrageenan|Carrageen|Carraghénane', 'STABILIZER', [0, 0, 0, 0, 0, 90], $gum, [60, 2, 20, 26.0], '', ''],
            ['CMC', 'Carbossimetilcellulosa|Carboxymethyl cellulose|Carboxymethylcellulose|Carboxyméthylcellulose', 'STABILIZER', [0, 0, 0, 0, 0, 94], $gum, [0, 0, 0, 14.0], '', ''],
            ['ALGINATE', 'Alginato di sodio|Sodium alginate|Natriumalginat|Alginate de sodium', 'STABILIZER', [0, 0, 0, 0, 0, 90], $gum, [60, 0, 20, 24.0], '', ''],
            ['GELATIN_200', 'Gelatina 200 Bloom|Gelatin 200 Bloom|Gelatine 200 Bloom|Gélatine 200 Bloom', 'STABILIZER', [0, 0, 0, 0, 0, 90], $gum, [0, 87, 350, 22.0], '', 'n'],
            ['STAB_NEUTRAL_DAIRY', 'Neutro per gelato al latte|Dairy gelato stabilizer|Stabilisator für Milchglace|Stabilisant pour glace au lait', 'STABILIZER', [10, 10, 0, 0, 0, 96], [10, 'sucrose'], [60, 0, 200, 14.0], 'milk', 'n'],
            ['STAB_NEUTRAL_FRUIT', 'Neutro per sorbetto|Sorbet stabilizer|Stabilisator für Sorbet|Stabilisant pour sorbet', 'STABILIZER', [10, 10, 0, 0, 0, 96], [10, 'sucrose'], [70, 0, 150, 13.0], '', ''],
            ['EMULSIFIER_MDG', 'Mono e digliceridi|Mono- and diglycerides|Mono- und Diglyceride|Mono- et diglycérides', 'STABILIZER', [0, 0, 0, 98, 0, 99], $gum, [0, 0, 880, 7.0], '', ''],
            ['LECITHIN_SOY', 'Lecitina di soia|Soy lecithin|Sojalecithin|Lécithine de soja', 'STABILIZER', [0, 0, 0, 95, 0, 98], $gum, [0, 0, 760, 9.0], 'soy', ''],
            ['LECITHIN_SUNFLOWER', 'Lecitina di girasole|Sunflower lecithin|Sonnenblumenlecithin|Lécithine de tournesol', 'STABILIZER', [0, 0, 0, 95, 0, 98], $gum, [0, 0, 760, 16.0], '', ''],
            ['CORN_STARCH', 'Amido di mais|Corn starch|Maisstärke|Fécule de maïs', 'STABILIZER', [0, 0, 0, 0, 0, 88], [0, 'none'], [1, 0.3, 350, 2.2], '', ''],
            ['RICE_STARCH', 'Amido di riso|Rice starch|Reisstärke|Amidon de riz', 'STABILIZER', [0, 0, 0, 0, 0, 88], [0, 'none'], [1, 0.3, 350, 5.0], '', ''],
            ['POTATO_STARCH', 'Fecola di patate|Potato starch|Kartoffelstärke|Fécule de pomme de terre', 'STABILIZER', [0, 0, 0, 0, 0, 82], [0, 'none'], [1, 0.1, 340, 3.0], '', ''],
        ];
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function nutsAndSeeds(): array
    {
        $sugar = [4, 'sucrose'];

        return [
            ['PASTE_PISTACHIO_BRONTE', 'Pasta pistacchio di Bronte DOP 100%|Bronte DOP pistachio paste 100%|Pistazienpaste Bronte DOP 100%|Pâte de pistache de Bronte DOP 100%', 'NUT_PASTE', [1, 6, 0, 52, 0, 98], $sugar, [10, 22, 600, 85.0], 'nuts', ''],
            ['PASTE_PISTACHIO_IRAN', 'Pasta pistacchio 100%|Pistachio paste 100%|Pistazienpaste 100%|Pâte de pistache 100%', 'NUT_PASTE', [1, 6, 0, 50, 0, 98], $sugar, [10, 21, 590, 38.0], 'nuts', ''],
            ['PASTE_PISTACHIO_SWEET', 'Pasta pistacchio zuccherata 50%|Sweetened pistachio paste 50%|Gezuckerte Pistazienpaste 50%|Pâte de pistache sucrée 50%', 'NUT_PASTE', [55, 60, 0, 28, 0, 98], [50, 'sucrose'], [5, 11, 530, 28.0], 'nuts', ''],
            ['PASTE_HAZELNUT_IGP', 'Pasta nocciola Piemonte IGP 100%|Piedmont IGP hazelnut paste 100%|Haselnusspaste Piemont IGP 100%|Pâte de noisette du Piémont IGP 100%', 'NUT_PASTE', [1, 4, 0, 62, 0, 99], [4, 'sucrose'], [9, 15, 640, 36.0], 'nuts', ''],
            ['PASTE_HAZELNUT_ROASTED', 'Pasta nocciola tostata 100%|Roasted hazelnut paste 100%|Geröstete Haselnusspaste 100%|Pâte de noisette torréfiée 100%', 'NUT_PASTE', [1, 4, 0, 62, 0, 99], [4, 'sucrose'], [9, 15, 640, 24.0], 'nuts', ''],
            ['PASTE_HAZELNUT_SWEET', 'Pasta nocciola zuccherata 50%|Sweetened hazelnut paste 50%|Gezuckerte Haselnusspaste 50%|Pâte de noisette sucrée 50%', 'NUT_PASTE', [55, 60, 0, 32, 0, 99], [50, 'sucrose'], [5, 7, 560, 18.0], 'nuts', ''],
            ['PASTE_ALMOND', 'Pasta di mandorla 100%|Almond paste 100%|Mandelpaste 100%|Pâte d\'amande 100%', 'NUT_PASTE', [1, 4, 0, 52, 0, 98], [4, 'sucrose'], [12, 21, 610, 22.0], 'nuts', ''],
            ['PASTE_ALMOND_BITTER', 'Pasta di mandorla amara|Bitter almond paste|Bittere Mandelpaste|Pâte d\'amande amère', 'NUT_PASTE', [1, 4, 0, 50, 0, 98], [4, 'sucrose'], [12, 21, 600, 30.0], 'nuts', ''],
            ['PASTE_WALNUT', 'Pasta di noci|Walnut paste|Walnusspaste|Pâte de noix', 'NUT_PASTE', [1, 3, 0, 65, 0, 98], [3, 'sucrose'], [7, 15, 680, 26.0], 'nuts', ''],
            ['PASTE_CASHEW', 'Pasta di anacardi|Cashew paste|Cashewpaste|Pâte de noix de cajou', 'NUT_PASTE', [3, 8, 0, 46, 0, 98], [6, 'sucrose'], [3, 18, 590, 24.0], 'nuts', ''],
            ['PASTE_PINE_NUT', 'Pasta di pinoli|Pine nut paste|Pinienkernpaste|Pâte de pignons', 'NUT_PASTE', [1, 3, 0, 65, 0, 97], [3, 'sucrose'], [4, 14, 670, 70.0], 'nuts', ''],
            ['PASTE_MACADAMIA', 'Pasta di macadamia|Macadamia paste|Macadamiapaste|Pâte de macadamia', 'NUT_PASTE', [1, 4, 0, 75, 0, 99], [4, 'sucrose'], [8, 8, 720, 45.0], 'nuts', ''],
            ['PASTE_PECAN', 'Pasta di noci pecan|Pecan paste|Pekannusspaste|Pâte de noix de pécan', 'NUT_PASTE', [1, 4, 0, 70, 0, 98], [4, 'sucrose'], [10, 9, 690, 40.0], 'nuts', ''],
            ['PASTE_PEANUT', 'Pasta di arachidi|Peanut paste|Erdnusspaste|Pâte d\'arachide', 'NUT_PASTE', [1, 5, 0, 50, 0, 99], [5, 'sucrose'], [8, 25, 590, 9.0], 'peanuts', ''],
            ['PASTE_PEANUT_SWEET', 'Burro di arachidi zuccherato|Sweetened peanut butter|Gezuckerte Erdnussbutter|Beurre de cacahuète sucré', 'NUT_PASTE', [10, 12, 0, 46, 0, 99], [10, 'sucrose'], [6, 22, 600, 8.0], 'peanuts', ''],
            ['PASTE_SESAME', 'Tahina|Tahini|Tahini|Tahini', 'NUT_PASTE', [0, 2, 0, 56, 0, 98], [0.5, 'none'], [9, 17, 640, 12.0], 'sesame', ''],
            ['PASTE_SESAME_BLACK', 'Tahina nera|Black tahini|Schwarzes Tahini|Tahini noir', 'NUT_PASTE', [0, 2, 0, 55, 0, 98], [0.5, 'none'], [10, 18, 630, 18.0], 'sesame', ''],
            ['PASTE_SUNFLOWER', 'Pasta di semi di girasole|Sunflower seed paste|Sonnenblumenkernpaste|Pâte de graines de tournesol', 'NUT_PASTE', [1, 3, 0, 52, 0, 98], [2, 'none'], [9, 21, 600, 10.0], '', ''],
            ['PASTE_PUMPKIN_SEED', 'Pasta di semi di zucca|Pumpkin seed paste|Kürbiskernpaste|Pâte de graines de courge', 'NUT_PASTE', [1, 3, 0, 50, 0, 98], [1, 'none'], [6, 30, 580, 16.0], '', ''],
            ['PASTE_CHESTNUT', 'Crema di castagne|Chestnut cream|Maronencreme|Crème de marrons', 'NUT_PASTE', [45, 50, 0, 1, 0, 70], [42, 'sucrose'], [4, 2, 270, 7.0], '', ''],
            ['PASTE_MARZIPAN', 'Marzapane 50%|Marzipan 50%|Marzipan 50%|Massepain 50%', 'NUT_PASTE', [50, 55, 0, 26, 0, 92], [48, 'sucrose'], [7, 9, 470, 14.0], 'nuts', ''],
            ['PASTE_PRALINE_HAZELNUT', 'Pralinato nocciola 50%|Hazelnut praline 50%|Haselnusspraliné 50%|Praliné noisette 50%', 'NUT_PASTE', [50, 55, 0, 32, 0, 99], [48, 'sucrose'], [5, 7, 570, 16.0], 'nuts', ''],
            ['PASTE_PRALINE_ALMOND', 'Pralinato mandorla 50%|Almond praline 50%|Mandelpraliné 50%|Praliné amande 50%', 'NUT_PASTE', [50, 55, 0, 30, 0, 99], [48, 'sucrose'], [6, 10, 560, 15.0], 'nuts', ''],
            ['PASTE_GIANDUJA', 'Pasta gianduia|Gianduja paste|Gianduja-Paste|Pâte gianduja', 'NUT_PASTE', [45, 48, 3, 38, 6, 99], [44, 'sucrose'], [4, 8, 570, 17.0], 'nuts,milk', 'n'],
            ['NUT_PISTACHIO_CHOPPED', 'Granella di pistacchio|Chopped pistachio|Pistazienkrokant|Pistaches concassées', 'NUT_PASTE', [1, 5, 0, 45, 0, 96], [6, 'sucrose'], [10, 20, 560, 40.0], 'nuts', ''],
            ['NUT_HAZELNUT_CHOPPED', 'Granella di nocciola|Chopped hazelnut|Haselnusskrokant|Noisettes concassées', 'NUT_PASTE', [1, 4, 0, 60, 0, 97], [4, 'sucrose'], [9, 15, 630, 18.0], 'nuts', ''],
            ['NUT_ALMOND_FLOUR', 'Farina di mandorle|Almond flour|Mandelmehl|Poudre d\'amande', 'NUT_PASTE', [1, 4, 0, 50, 0, 97], [4, 'sucrose'], [12, 21, 580, 14.0], 'nuts', ''],
            ['NUT_COCONUT_SHREDDED', 'Cocco rapé|Shredded coconut|Kokosraspeln|Noix de coco râpée', 'NUT_PASTE', [1, 3, 0, 62, 0, 97], [7, 'sucrose'], [16, 6, 650, 6.0], '', ''],
        ];
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function chocolate(): array
    {
        $rows = [];
        // [code, it|en|de|fr, sugar, cocoa_mass, cocoa_butter(added), milk powder slng, milk fat, extra allergens]
        $couvertures = [
            ['CHOC_DARK_99', 'Cioccolato fondente 99%|Dark chocolate 99%|Zartbitterschokolade 99%|Chocolat noir 99%', 1, 90, 9, 0, 0, 'soy'],
            ['CHOC_DARK_85', 'Cioccolato fondente 85%|Dark chocolate 85%|Zartbitterschokolade 85%|Chocolat noir 85%', 14, 70, 15, 0, 0, 'soy'],
            ['CHOC_DARK_80', 'Cioccolato fondente 80%|Dark chocolate 80%|Zartbitterschokolade 80%|Chocolat noir 80%', 19, 65, 15, 0, 0, 'soy'],
            ['CHOC_DARK_70', 'Cioccolato fondente 70%|Dark chocolate 70%|Zartbitterschokolade 70%|Chocolat noir 70%', 29, 55, 15, 0, 0, 'soy'],
            ['CHOC_DARK_64', 'Cioccolato fondente 64%|Dark chocolate 64%|Zartbitterschokolade 64%|Chocolat noir 64%', 35, 49, 15, 0, 0, 'soy'],
            ['CHOC_DARK_60', 'Cioccolato fondente 60%|Dark chocolate 60%|Zartbitterschokolade 60%|Chocolat noir 60%', 39, 46, 14, 0, 0, 'soy'],
            ['CHOC_DARK_55', 'Cioccolato fondente 55%|Dark chocolate 55%|Zartbitterschokolade 55%|Chocolat noir 55%', 44, 41, 14, 0, 0, 'soy'],
            ['CHOC_DARK_52', 'Cioccolato fondente 52%|Dark chocolate 52%|Zartbitterschokolade 52%|Chocolat noir 52%', 47, 39, 13, 0, 0, 'soy'],
            ['CHOC_MILK_45', 'Cioccolato al latte 45%|Milk chocolate 45%|Milchschokolade 45%|Chocolat au lait 45%', 38, 30, 15, 14, 3, 'milk,soy'],
            ['CHOC_MILK_40', 'Cioccolato al latte 40%|Milk chocolate 40%|Milchschokolade 40%|Chocolat au lait 40%', 42, 26, 14, 15, 4, 'milk,soy'],
            ['CHOC_MILK_38', 'Cioccolato al latte 38%|Milk chocolate 38%|Milchschokolade 38%|Chocolat au lait 38%', 44, 24, 14, 16, 4, 'milk,soy'],
            ['CHOC_MILK_35', 'Cioccolato al latte 35%|Milk chocolate 35%|Milchschokolade 35%|Chocolat au lait 35%', 46, 22, 13, 17, 5, 'milk,soy'],
            ['CHOC_MILK_33', 'Cioccolato al latte 33%|Milk chocolate 33%|Milchschokolade 33%|Chocolat au lait 33%', 48, 20, 13, 18, 5, 'milk,soy'],
            ['CHOC_WHITE_33', 'Cioccolato bianco 33%|White chocolate 33%|Weisse Schokolade 33%|Chocolat blanc 33%', 45, 0, 33, 20, 3, 'milk,soy'],
            ['CHOC_WHITE_30', 'Cioccolato bianco 30%|White chocolate 30%|Weisse Schokolade 30%|Chocolat blanc 30%', 48, 0, 30, 20, 3, 'milk,soy'],
            ['CHOC_WHITE_28', 'Cioccolato bianco 28%|White chocolate 28%|Weisse Schokolade 28%|Chocolat blanc 28%', 50, 0, 28, 19, 3, 'milk,soy'],
            ['CHOC_RUBY', 'Cioccolato ruby|Ruby chocolate|Ruby-Schokolade|Chocolat ruby', 47, 0, 37, 17, 2, 'milk,soy'],
            ['CHOC_BLOND', 'Cioccolato biondo caramellato|Caramelized blond chocolate|Karamellisierte blonde Schokolade|Chocolat blond caramélisé', 43, 0, 33, 22, 3, 'milk,soy'],
            ['CHOC_VEGAN_DARK', 'Cioccolato fondente vegano 60%|Vegan dark chocolate 60%|Vegane Zartbitterschokolade 60%|Chocolat noir vegan 60%', 38, 46, 14, 0, 0, ''],
            ['CHOC_GIANDUJA_MILK', 'Gianduia al latte|Milk gianduja|Milch-Gianduja|Gianduja au lait', 42, 8, 22, 10, 3, 'milk,nuts,soy'],
        ];

        foreach ($couvertures as [$code, $names, $sugar, $mass, $butter, $slng, $milkFat, $allergens]) {
            $fat = $butter + $mass * 0.54 + $milkFat;
            $rows[] = [$code, $names, 'CHOCOLATE', [$sugar, $sugar, $milkFat, round($fat - $milkFat, 1), $slng, 99], [$sugar, 'sucrose'], [round($mass * 0.3, 1), round($mass * 0.14 + $slng * 0.36, 1), round($fat * 9 + $sugar * 4 + 40), 6 + $mass * 0.06 + ($slng > 0 ? 0 : 1)], $allergens, $slng > 0 ? 'n' : '', ['cocoa_butter' => round($butter + $mass * 0.54, 1), 'cocoa_mass' => $mass]];
        }

        return array_merge($rows, [
            ['COCOA_POWDER_10', 'Cacao in polvere 10-12%|Cocoa powder 10-12%|Kakaopulver 10-12%|Cacao en poudre 10-12%', 'CHOCOLATE', [0, 0, 0, 11, 0, 96], [0, 'none'], [28, 20, 340, 7.0], '', '', ['cocoa_butter' => 11, 'cocoa_mass' => 85]],
            ['COCOA_POWDER_22', 'Cacao in polvere 22-24%|Cocoa powder 22-24%|Kakaopulver 22-24%|Cacao en poudre 22-24%', 'CHOCOLATE', [0, 0, 0, 23, 0, 97], [0, 'none'], [26, 19, 400, 9.0], '', '', ['cocoa_butter' => 23, 'cocoa_mass' => 90]],
            ['COCOA_BUTTER', 'Burro di cacao|Cocoa butter|Kakaobutter|Beurre de cacao', 'CHOCOLATE', [0, 0, 0, 99.5, 0, 99.5], [0, 'none'], [0, 0, 900, 16.0], '', '', ['cocoa_butter' => 100, 'cocoa_mass' => 0]],
            ['COCOA_MASS', 'Pasta di cacao|Cocoa mass|Kakaomasse|Masse de cacao', 'CHOCOLATE', [0, 0, 0, 54, 0, 99], [0, 'none'], [14, 14, 560, 11.0], '', '', ['cocoa_butter' => 54, 'cocoa_mass' => 100]],
            ['COCOA_NIBS', 'Granella di cacao|Cocoa nibs|Kakaonibs|Grué de cacao', 'CHOCOLATE', [0, 0, 0, 50, 0, 98], [0, 'none'], [33, 14, 550, 14.0], '', '', ['cocoa_butter' => 50, 'cocoa_mass' => 100]],
            ['CHOC_CHIPS_BAKE', 'Gocce di cioccolato da forno|Baking chocolate chips|Backschokoladentropfen|Pépites de chocolat à cuire', 'CHOCOLATE', [40, 40, 0, 28, 0, 99], [40, 'sucrose'], [8, 5, 500, 8.0], 'soy', ''],
            ['CHOC_SPREAD', 'Crema spalmabile al cacao e nocciole|Cocoa hazelnut spread|Kakao-Haselnuss-Aufstrich|Pâte à tartiner cacao noisette', 'CHOCOLATE', [56, 58, 0.5, 31, 2, 98], [55, 'sucrose'], [3, 6, 540, 6.0], 'milk,nuts,soy', 'n'],
        ]);
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function flavorings(): array
    {
        $none = [0, 'none'];

        return [
            ['VANILLA_EXTRACT', 'Estratto di vaniglia|Vanilla extract|Vanilleextrakt|Extrait de vanille', 'FLAVOR', [8, 70, 0, 0, 0, 30], [8, 'sucrose'], [0, 0, 280, 120.0], '', ''],
            ['VANILLA_BEAN', 'Baccello di vaniglia|Vanilla bean|Vanilleschote|Gousse de vanille', 'FLAVOR', [0, 0, 0, 0, 0, 80], $none, [0, 0, 280, 900.0], '', ''],
            ['VANILLA_PASTE', 'Pasta di vaniglia|Vanilla bean paste|Vanillepaste|Pâte de vanille', 'FLAVOR', [60, 70, 0, 0, 0, 78], [60, 'sucrose'], [0, 0, 270, 160.0], '', ''],
            ['COFFEE_ESPRESSO', 'Caffè espresso|Espresso coffee|Espresso|Café expresso', 'FLAVOR', [0, 0, 0, 0, 0, 2], $none, [0, 0.1, 2, 5.0], '', ''],
            ['COFFEE_PASTE', 'Pasta di caffè|Coffee paste|Kaffeepaste|Pâte de café', 'FLAVOR', [40, 55, 0, 1, 0, 75], [40, 'sucrose'], [0, 3, 250, 22.0], '', ''],
            ['COFFEE_INSTANT', 'Caffè solubile|Instant coffee|Instantkaffee|Café soluble', 'FLAVOR', [0, 0, 0, 0.5, 0, 96], $none, [0, 12, 350, 38.0], '', ''],
            ['MATCHA', 'Tè matcha|Matcha tea|Matcha-Tee|Thé matcha', 'FLAVOR', [0, 0, 0, 5, 0, 95], $none, [38, 30, 320, 120.0], '', ''],
            ['TEA_EARL_GREY', 'Infuso tè Earl Grey|Earl Grey tea infusion|Earl-Grey-Aufguss|Infusion de thé Earl Grey', 'FLAVOR', [0, 0, 0, 0, 0, 1], $none, [0, 0, 1, 40.0], '', ''],
            ['CINNAMON', 'Cannella in polvere|Ground cinnamon|Zimtpulver|Cannelle en poudre', 'FLAVOR', [0, 0, 0, 1, 0, 90], $none, [53, 4, 250, 30.0], '', ''],
            ['GINGER', 'Zenzero in polvere|Ground ginger|Ingwerpulver|Gingembre en poudre', 'FLAVOR', [0, 0, 0, 4, 0, 90], $none, [14, 9, 335, 28.0], '', ''],
            ['SAFFRON', 'Zafferano|Saffron|Safran|Safran', 'FLAVOR', [0, 0, 0, 6, 0, 88], $none, [4, 11, 310, 9000.0], '', ''],
            ['MINT_FRESH', 'Menta fresca|Fresh mint|Frische Minze|Menthe fraîche', 'FLAVOR', [0, 0, 0, 0.7, 0, 14], $none, [8, 3.8, 44, 18.0], '', ''],
            ['BASIL_FRESH', 'Basilico fresco|Fresh basil|Frisches Basilikum|Basilic frais', 'FLAVOR', [0, 0, 0, 0.6, 0, 9], $none, [1.6, 3.2, 23, 14.0], '', ''],
            ['ROSEMARY', 'Rosmarino|Rosemary|Rosmarin|Romarin', 'FLAVOR', [0, 0, 0, 5, 0, 32], $none, [14, 3.3, 131, 20.0], '', ''],
            ['LICORICE', 'Liquirizia pura|Pure licorice|Reine Lakritze|Réglisse pure', 'FLAVOR', [25, 30, 0, 0, 0, 80], [25, 'sucrose'], [3, 3, 300, 24.0], 'gluten', ''],
            ['OIL_OLIVE_EVO', 'Olio extravergine d\'oliva|Extra virgin olive oil|Natives Olivenöl extra|Huile d\'olive vierge extra', 'FLAVOR', [0, 0, 0, 100, 0, 100], $none, [0, 0, 884, 12.0], '', ''],
            ['OIL_SUNFLOWER', 'Olio di girasole|Sunflower oil|Sonnenblumenöl|Huile de tournesol', 'FLAVOR', [0, 0, 0, 100, 0, 100], $none, [0, 0, 884, 2.5], '', ''],
            ['BALSAMIC', 'Aceto balsamico di Modena IGP|Balsamic vinegar of Modena PGI|Aceto Balsamico di Modena g.g.A.|Vinaigre balsamique de Modène IGP', 'FLAVOR', [20, 30, 0, 0, 0, 25], [20, 'invert'], [0, 0.5, 88, 14.0], 'sulphites', ''],
            ['CHEESE_GORGONZOLA', 'Gorgonzola DOP|Gorgonzola PDO|Gorgonzola g.U.|Gorgonzola AOP', 'SAVORY', [0, 0, 27, 0, 3, 40], [0, 'none'], [0, 19, 340, 14.0], 'milk', 'n'],
            ['CHEESE_PARMESAN', 'Parmigiano Reggiano DOP 24 mesi|Parmigiano Reggiano PDO 24 months|Parmigiano Reggiano g.U. 24 Monate|Parmigiano Reggiano AOP 24 mois', 'SAVORY', [0, 0, 28, 0, 5, 68], [0, 'none'], [0, 33, 392, 24.0], 'milk', 'n'],
            ['CHEESE_PECORINO', 'Pecorino romano DOP|Pecorino romano PDO|Pecorino Romano g.U.|Pecorino romano AOP', 'SAVORY', [0, 0, 29, 0, 5, 70], [0, 'none'], [0, 28, 387, 19.0], 'milk', 'n'],
            ['CHEESE_BURRATA', 'Burrata|Burrata|Burrata|Burrata', 'SAVORY', [0, 0, 24, 0, 5, 36], [1, 'none'], [0, 15, 290, 12.0], 'milk', 'n'],
            ['TOMATO_CONCENTRATE', 'Concentrato di pomodoro|Tomato paste|Tomatenmark|Concentré de tomate', 'SAVORY', [20, 25, 0, 0.5, 0, 28], [14, 'invert'], [4, 4.3, 82, 3.0], '', ''],
            ['PEPPER_BLACK', 'Pepe nero|Black pepper|Schwarzer Pfeffer|Poivre noir', 'SAVORY', [0, 0, 0, 3, 0, 90], $none, [26, 10, 250, 25.0], '', ''],
            ['SALT_FINE', 'Sale fino|Fine salt|Feines Salz|Sel fin', 'SAVORY', [0, 600, 0, 0, 0, 100], $none, [0, 0, 0, 0.5], '', ''],
            ['SALT_FLEUR', 'Fior di sale|Fleur de sel|Fleur de Sel|Fleur de sel', 'SAVORY', [0, 600, 0, 0, 0, 100], $none, [0, 0, 0, 14.0], '', ''],
            ['ANCHOVY_PASTE', 'Colatura di alici|Anchovy sauce|Sardellensauce|Colatura d\'anchois', 'SAVORY', [0, 100, 0, 0.5, 0, 35], $none, [0, 12, 70, 40.0], 'fish', 'n'],
            ['BACON_SMOKED', 'Pancetta affumicata|Smoked bacon|Geräucherter Speck|Poitrine fumée', 'SAVORY', [0, 0, 0, 40, 0, 52], $none, [0, 14, 420, 12.0], '', 'n'],
            ['CARAMEL_SALTED', 'Caramello salato|Salted caramel|Salzkaramell|Caramel au sel', 'SAUCE', [70, 80, 6, 4, 6, 78], [60, 'sucrose'], [0, 1.5, 360, 7.0], 'milk', 'n'],
            ['DULCE_DE_LECHE', 'Dulce de leche|Dulce de leche|Dulce de leche|Dulce de leche', 'SAUCE', [60, 70, 6.5, 0, 9, 71], [55, 'sucrose'], [0, 6, 320, 6.5], 'milk', 'n'],
            ['CARAMEL_SAUCE', 'Salsa caramello|Caramel sauce|Karamellsauce|Sauce caramel', 'SAUCE', [75, 90, 0, 3, 0, 75], [70, 'sucrose'], [0, 0.5, 320, 6.0], 'milk', 'n'],
            ['AMARENA_SAUCE', 'Salsa all\'amarena|Sour cherry sauce|Sauerkirschsauce|Sauce à la griotte', 'SAUCE', [55, 80, 0, 0, 0, 68], [62, 'invert'], [1, 0.5, 260, 8.0], '', ''],
            ['CHOCOLATE_SAUCE', 'Salsa al cioccolato|Chocolate sauce|Schokoladensauce|Sauce au chocolat', 'SAUCE', [55, 60, 0, 6, 0, 65], [50, 'glucose'], [2, 2, 270, 5.0], 'soy', ''],
            ['RASPBERRY_RIPPLE', 'Variegatura al lampone|Raspberry ripple|Himbeer-Ripple|Coulis de framboise ripple', 'SAUCE', [50, 75, 0, 0, 0, 62], [58, 'invert'], [2, 0.5, 240, 7.0], '', ''],
            ['PISTACHIO_RIPPLE', 'Variegatura al pistacchio|Pistachio ripple|Pistazien-Ripple|Ripple à la pistache', 'SAUCE', [45, 55, 0, 18, 0, 90], [42, 'sucrose'], [4, 6, 480, 30.0], 'nuts', ''],
            ['HAZELNUT_RIPPLE', 'Variegatura alla nocciola|Hazelnut ripple|Haselnuss-Ripple|Ripple à la noisette', 'SAUCE', [45, 50, 0, 22, 0, 92], [42, 'sucrose'], [3, 5, 500, 14.0], 'nuts', ''],
            ['COOKIE_CRUMBLE', 'Crumble di frollini|Shortbread crumble|Mürbeteig-Streusel|Crumble sablé', 'CRUMBLE', [25, 28, 8, 8, 2, 96], [25, 'sucrose'], [2, 6, 460, 7.0], 'gluten,milk,eggs', 'n'],
            ['BISCUIT_CRUMBS', 'Briciole di biscotto|Biscuit crumbs|Kekskrümel|Miettes de biscuit', 'CRUMBLE', [22, 25, 3, 12, 0, 97], [22, 'sucrose'], [3, 7, 450, 4.5], 'gluten,milk', 'n'],
            ['AMARETTI_CRUMBS', 'Amaretti sbriciolati|Crushed amaretti|Zerkleinerte Amaretti|Amaretti émiettés', 'CRUMBLE', [60, 65, 0, 15, 0, 96], [55, 'sucrose'], [4, 8, 430, 14.0], 'nuts,eggs', 'n'],
            ['MERINGUE_CRUMBS', 'Meringa sbriciolata|Crushed meringue|Zerkleinerte Meringue|Meringue émiettée', 'CRUMBLE', [95, 95, 0, 0, 0, 98], [95, 'sucrose'], [0, 4, 390, 9.0], 'eggs', 'n'],
            ['WAFER_CRUMBS', 'Cialda sbriciolata|Crushed wafer|Zerkleinerte Waffel|Gaufrette émiettée', 'CRUMBLE', [5, 6, 0, 8, 0, 96], [5, 'sucrose'], [3, 9, 430, 6.0], 'gluten', ''],
            ['CANNOLI_SHELL', 'Scorza di cannolo|Cannoli shell|Cannoli-Hülle|Coque de cannoli', 'CRUMBLE', [8, 8, 0, 18, 0, 96], [8, 'sucrose'], [3, 9, 470, 8.0], 'gluten', ''],
            ['BRIOCHE_CRUMBS', 'Brioche sbriciolata|Brioche crumbs|Briochekrümel|Brioche émiettée', 'CRUMBLE', [10, 12, 3, 8, 3, 80], [10, 'sucrose'], [2, 8, 380, 5.0], 'gluten,milk,eggs', 'n'],
            ['CORNFLAKES', 'Fiocchi di mais|Cornflakes|Cornflakes|Corn-flakes', 'CRUMBLE', [8, 8, 0, 1, 0, 96], [8, 'sucrose'], [3, 7, 375, 4.0], 'gluten', ''],
            ['CANDIED_ORANGE', 'Arancia candita|Candied orange|Kandierte Orange|Orange confite', 'CRUMBLE', [60, 80, 0, 0, 0, 75], [70, 'invert'], [2, 0.5, 290, 9.0], '', ''],
            ['RAISINS', 'Uvetta|Raisins|Rosinen|Raisins secs', 'CRUMBLE', [70, 100, 0, 0, 0, 82], [65, 'invert'], [4, 3, 300, 6.0], 'sulphites', ''],
            ['LIQUEUR_RUM', 'Rum|Rum|Rum|Rhum', 'LIQUEUR', [0, 700, 0, 0, 0, 0], $none, [0, 0, 230, 20.0], '', ''],
            ['LIQUEUR_AMARETTO', 'Liquore amaretto|Amaretto liqueur|Amaretto-Likör|Liqueur d\'amaretto', 'LIQUEUR', [30, 450, 0, 0, 0, 28], [28, 'sucrose'], [0, 0, 300, 14.0], 'nuts', ''],
            ['LIQUEUR_LIMONCELLO', 'Limoncello|Limoncello|Limoncello|Limoncello', 'LIQUEUR', [30, 450, 0, 0, 0, 28], [28, 'sucrose'], [0, 0, 280, 12.0], '', ''],
            ['LIQUEUR_MARSALA', 'Marsala secco|Dry Marsala|Trockener Marsala|Marsala sec', 'LIQUEUR', [3, 330, 0, 0, 0, 5], [3, 'invert'], [0, 0, 130, 6.0], 'sulphites', ''],
            ['LIQUEUR_WHISKY', 'Whisky|Whisky|Whisky|Whisky', 'LIQUEUR', [0, 700, 0, 0, 0, 0], $none, [0, 0, 230, 25.0], '', ''],
            ['LIQUEUR_BAILEYS', 'Crema di whiskey|Irish cream liqueur|Irischer Cremelikör|Crème de whisky', 'LIQUEUR', [25, 380, 3.5, 0, 3, 28], [22, 'sucrose'], [0, 1, 330, 16.0], 'milk', 'n'],
            ['ROSE_WATER', 'Acqua di rose|Rose water|Rosenwasser|Eau de rose', 'FLAVOR', [0, 0, 0, 0, 0, 0.5], $none, [0, 0, 0, 12.0], '', ''],
            ['ORANGE_BLOSSOM_WATER', 'Acqua di fiori d\'arancio|Orange blossom water|Orangenblütenwasser|Eau de fleur d\'oranger', 'FLAVOR', [0, 0, 0, 0, 0, 0.5], $none, [0, 0, 0, 12.0], '', ''],
            ['LEMON_ZEST', 'Scorza di limone|Lemon zest|Zitronenschale|Zeste de citron', 'FLAVOR', [3, 5, 0, 0.3, 0, 28], [3, 'invert'], [10, 1.5, 47, 9.0], '', ''],
            ['WATER', 'Acqua|Water|Wasser|Eau', 'WATER', [0, 0, 0, 0, 0, 0], [0, 'none'], [0, 0, 0, 0.01], '', ''],
        ];
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function fruits(): array
    {
        // [code, it|en|de|fr, brix, cost fresh purée, cost frozen purée]
        $fruits = [
            ['STRAWBERRY', 'fragola|strawberry|Erdbeere|fraise', 8, 5.5, 4.8],
            ['LEMON', 'limone (succo)|lemon (juice)|Zitrone (Saft)|citron (jus)', 8, 3.2, 3.0],
            ['MANGO', 'mango|mango|Mango|mangue', 15, 6.5, 5.2],
            ['RASPBERRY', 'lampone|raspberry|Himbeere|framboise', 9, 12.0, 9.5],
            ['PASSION_FRUIT', 'frutto della passione|passion fruit|Passionsfrucht|fruit de la passion', 15, 11.0, 8.5],
            ['SOUR_CHERRY', 'amarena|sour cherry|Sauerkirsche|griotte', 16, 8.5, 7.0],
            ['PEAR', 'pera|pear|Birne|poire', 12, 3.8, 3.4],
            ['GREEN_APPLE', 'mela verde|green apple|grüner Apfel|pomme verte', 11, 3.0, 2.8],
            ['BANANA', 'banana|banana|Banane|banane', 20, 3.2, 2.9],
            ['PEACH', 'pesca|peach|Pfirsich|pêche', 11, 3.5, 3.0],
            ['APRICOT', 'albicocca|apricot|Aprikose|abricot', 12, 4.0, 3.5],
            ['PINEAPPLE', 'ananas|pineapple|Ananas|ananas', 13, 3.6, 3.2],
            ['MELON', 'melone|melon|Melone|melon', 9, 2.8, 2.6],
            ['WATERMELON', 'anguria|watermelon|Wassermelone|pastèque', 8, 2.2, 2.0],
            ['ORANGE', 'arancia|orange|Orange|orange', 11, 2.5, 2.3],
            ['MANDARIN', 'mandarino|mandarin|Mandarine|mandarine', 11, 3.0, 2.8],
            ['GRAPEFRUIT', 'pompelmo|grapefruit|Grapefruit|pamplemousse', 9, 3.0, 2.8],
            ['KIWI', 'kiwi|kiwi|Kiwi|kiwi', 14, 3.8, 3.4],
            ['BLUEBERRY', 'mirtillo|blueberry|Heidelbeere|myrtille', 12, 10.0, 8.0],
            ['BLACKBERRY', 'mora|blackberry|Brombeere|mûre', 10, 9.5, 7.5],
            ['BLACKCURRANT', 'ribes nero|blackcurrant|schwarze Johannisbeere|cassis', 14, 9.0, 7.0],
            ['FIG', 'fico|fig|Feige|figue', 18, 6.0, 5.2],
            ['PLUM', 'prugna|plum|Pflaume|prune', 14, 4.0, 3.5],
            ['CHERRY', 'ciliegia|cherry|Kirsche|cerise', 16, 7.0, 6.0],
            ['POMEGRANATE', 'melograno|pomegranate|Granatapfel|grenade', 16, 7.5, 6.5],
            ['LIME', 'lime (succo)|lime (juice)|Limette (Saft)|citron vert (jus)', 8, 4.5, 4.0],
            ['PAPAYA', 'papaya|papaya|Papaya|papaye', 11, 5.0, 4.4],
            ['LYCHEE', 'litchi|lychee|Litschi|litchi', 17, 7.0, 6.0],
            ['GRAPE', 'uva|grape|Traube|raisin', 17, 3.5, 3.0],
            ['PERSIMMON', 'cachi|persimmon|Kaki|kaki', 18, 4.5, 4.0],
            ['COCONUT_WATER_FRUIT', 'cocco (polpa)|coconut (pulp)|Kokos (Fruchtfleisch)|noix de coco (pulpe)', 9, 5.0, 4.5],
            ['PRICKLY_PEAR', 'fico d\'India|prickly pear|Kaktusfeige|figue de Barbarie', 13, 6.0, 5.2],
            ['BERGAMOT', 'bergamotto|bergamot|Bergamotte|bergamote', 9, 8.0, 7.0],
            ['CHESTNUT_FRUIT_PUREE', 'castagna (purea)|chestnut (purée)|Marone (Püree)|châtaigne (purée)', 28, 6.0, 5.2],
            ['AVOCADO', 'avocado|avocado|Avocado|avocat', 3, 6.5, 5.5],
            ['RHUBARB', 'rabarbaro|rhubarb|Rhabarber|rhubarbe', 4, 3.5, 3.2],
            ['QUINCE', 'mela cotogna|quince|Quitte|coing', 12, 4.5, 4.0],
            ['CLEMENTINE', 'clementina|clementine|Clementine|clémentine', 11, 3.0, 2.8],
            ['REDCURRANT', 'ribes rosso|redcurrant|rote Johannisbeere|groseille', 10, 9.0, 7.5],
            ['ELDERFLOWER', 'sambuco (sciroppo di fiori)|elderflower (cordial)|Holunderblüte (Sirup)|sureau (sirop de fleurs)', 40, 7.0, 6.5],
            ['MULBERRY', 'gelso|mulberry|Maulbeere|mûre blanche', 12, 9.0, 7.5],
            ['TANGERINE_CANDIED_PEEL', 'cedro candito|candied citron|kandierte Zitronat|cédrat confit', 55, 8.0, 7.5],
            ['DATE', 'dattero (purea)|date (purée)|Dattel (Püree)|datte (purée)', 60, 6.5, 6.0],
            ['PRUNE', 'prugna secca (purea)|prune (purée)|Backpflaume (Püree)|pruneau (purée)', 40, 5.5, 5.0],
            ['GUAVA', 'guava|guava|Guave|goyave', 10, 6.0, 5.2],
            ['TAMARIND', 'tamarindo|tamarind|Tamarinde|tamarin', 35, 8.0, 7.0],
            ['YUZU', 'yuzu (succo)|yuzu (juice)|Yuzu (Saft)|yuzu (jus)', 8, 28.0, 25.0],
            ['CUSTARD_APPLE', 'annona|custard apple|Rahmapfel|corossol', 20, 9.0, 8.0],
            ['DRAGON_FRUIT', 'pitaya|dragon fruit|Drachenfrucht|pitaya', 10, 8.0, 7.0],
        ];
        $rows = [];

        foreach ($fruits as [$code, $names, $brix, $fresh, $frozen]) {
            [$it, $en, $de, $fr] = explode('|', $names);
            $base = [round($brix * 0.95, 1), round($brix * 1.15, 1), 0, 0.2, 0, round($brix + 2.5, 1)];
            $nutri = [1.8, 0.8, round($brix * 4 + 6), 0];
            $extra = ['brix' => $brix];
            $rows[] = ['FRUIT_'.$code, "Purea di {$it} fresca|Fresh {$en} purée|Frisches {$de}-Püree|Purée de {$fr} fraîche", 'FRUIT', $base, [$brix, 'invert'], [$nutri[0], $nutri[1], $nutri[2], $fresh], '', '', $extra];
            $rows[] = ['FRUIT_'.$code.'_FRZ', "Purea di {$it} surgelata|Frozen {$en} purée|Tiefgekühltes {$de}-Püree|Purée de {$fr} surgelée", 'FRUIT', $base, [$brix, 'invert'], [$nutri[0], $nutri[1], $nutri[2], $frozen], '', 'f', $extra];
        }

        return $rows;
    }
}
