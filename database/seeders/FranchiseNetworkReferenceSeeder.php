<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\FranchiseNetwork\Models\AuditTemplate;
use Modules\FranchiseNetwork\Models\BenchmarkDimension;
use Modules\FranchiseNetwork\Models\FranchiseFormula;

/**
 * Global catalogues of the franchise network (Mod 244), shared by every company and read-only for them: royalty and marketing fund
 * formula presets (Italian and Swiss), store audit checklist templates and the benchmark dimensions. All the wording is seed data in
 * the four languages (it, en, de, fr); the interface reads it from the API, no text lives in the frontend. Idempotent by code.
 */
final class FranchiseNetworkReferenceSeeder extends Seeder
{
    public function run(): void
    {
        app(TenantContext::class)->runWithoutTenant(function (): void {
            $this->formulas();
            $this->auditTemplates();
            $this->benchmarkDimensions();
        });
    }

    private function formulas(): void
    {
        $presets = [
            [
                'code' => 'RETAIL-FLAT-6-2',
                'labels' => ['it' => 'Retail standard: royalty 6% e fondo marketing 2%', 'en' => 'Standard retail: 6% royalty and 2% marketing fund', 'de' => 'Standard-Einzelhandel: 6 % Royalty und 2 % Marketingfonds', 'fr' => 'Commerce standard : redevance 6 % et fonds marketing 2 %'],
                'descriptions' => ['it' => 'Aliquota unica sul venduto netto mensile, senza minimo garantito né agevolazioni di avvio.', 'en' => 'Single rate on the monthly net sales, with no minimum guarantee and no start-up relief.', 'de' => 'Einheitssatz auf den monatlichen Nettoumsatz, ohne Mindestgarantie und ohne Anlaufvergünstigung.', 'fr' => 'Taux unique sur le chiffre d\'affaires net mensuel, sans minimum garanti ni allègement de démarrage.'],
                'royalty_tiers' => [['from' => 0, 'to' => null, 'rate' => 6]],
                'marketing_tiers' => [['from' => 0, 'to' => null, 'rate' => 2]],
                'minimum_guarantee' => 0, 'startup_months' => 0, 'startup_mode' => 'none', 'startup_reduction_percent' => 0, 'ramp_start_percent' => 0, 'startup_applies_to_marketing' => false, 'currency' => 'EUR',
            ],
            [
                'code' => 'RETAIL-PROGRESSIVE-IT',
                'labels' => ['it' => 'Retail a scaglioni con minimo garantito e avvio graduale', 'en' => 'Tiered retail with minimum guarantee and gradual start-up', 'de' => 'Gestaffelter Einzelhandel mit Mindestgarantie und gestaffeltem Anlauf', 'fr' => 'Commerce par tranches avec minimum garanti et démarrage progressif'],
                'descriptions' => ['it' => 'Royalty 7% fino a 50.000, 6% fino a 100.000, 5% oltre; fondo marketing 2% fino a 100.000, 1,5% oltre; minimo garantito di 1.500 al mese; nei primi 6 mesi la royalty sale dal 25% al 100%.', 'en' => 'Royalty 7% up to 50,000, 6% up to 100,000, 5% above; marketing fund 2% up to 100,000, 1.5% above; monthly minimum guarantee of 1,500; in the first 6 months the royalty ramps up from 25% to 100%.', 'de' => 'Royalty 7 % bis 50.000, 6 % bis 100.000, darüber 5 %; Marketingfonds 2 % bis 100.000, darüber 1,5 %; monatliche Mindestgarantie von 1.500; in den ersten 6 Monaten steigt die Royalty von 25 % auf 100 %.', 'fr' => 'Redevance 7 % jusqu\'à 50 000, 6 % jusqu\'à 100 000, 5 % au-delà ; fonds marketing 2 % jusqu\'à 100 000, 1,5 % au-delà ; minimum garanti de 1 500 par mois ; pendant les 6 premiers mois la redevance passe de 25 % à 100 %.'],
                'royalty_tiers' => [['from' => 0, 'to' => 50000, 'rate' => 7], ['from' => 50000, 'to' => 100000, 'rate' => 6], ['from' => 100000, 'to' => null, 'rate' => 5]],
                'marketing_tiers' => [['from' => 0, 'to' => 100000, 'rate' => 2], ['from' => 100000, 'to' => null, 'rate' => 1.5]],
                'minimum_guarantee' => 1500, 'startup_months' => 6, 'startup_mode' => 'ramp_up', 'startup_reduction_percent' => 0, 'ramp_start_percent' => 25, 'startup_applies_to_marketing' => false, 'currency' => 'EUR',
            ],
            [
                'code' => 'FOOD-MIN-GUARANTEE',
                'labels' => ['it' => 'Food: royalty 5% con minimo garantito ed esenzione di avvio', 'en' => 'Food: 5% royalty with minimum guarantee and start-up exemption', 'de' => 'Food: 5 % Royalty mit Mindestgarantie und Anlaufbefreiung', 'fr' => 'Food : redevance 5 % avec minimum garanti et exonération de démarrage'],
                'descriptions' => ['it' => 'Royalty 5% e fondo marketing 1,5%; minimo garantito di 1.200 al mese; esenzione totale della royalty nei primi 3 mesi (anche del minimo).', 'en' => '5% royalty and 1.5% marketing fund; monthly minimum guarantee of 1,200; full royalty exemption for the first 3 months (the minimum too).', 'de' => '5 % Royalty und 1,5 % Marketingfonds; monatliche Mindestgarantie von 1.200; vollständige Royalty-Befreiung in den ersten 3 Monaten (auch vom Minimum).', 'fr' => 'Redevance 5 % et fonds marketing 1,5 % ; minimum garanti de 1 200 par mois ; exonération totale de la redevance pendant les 3 premiers mois (minimum compris).'],
                'royalty_tiers' => [['from' => 0, 'to' => null, 'rate' => 5]],
                'marketing_tiers' => [['from' => 0, 'to' => null, 'rate' => 1.5]],
                'minimum_guarantee' => 1200, 'startup_months' => 3, 'startup_mode' => 'full_exemption', 'startup_reduction_percent' => 0, 'ramp_start_percent' => 0, 'startup_applies_to_marketing' => false, 'currency' => 'EUR',
            ],
            [
                'code' => 'RETAIL-SWISS-CHF',
                'labels' => ['it' => 'Retail svizzero in CHF con tariffa ridotta di avvio', 'en' => 'Swiss retail in CHF with reduced start-up rate', 'de' => 'Schweizer Einzelhandel in CHF mit reduziertem Anlauftarif', 'fr' => 'Commerce suisse en CHF avec tarif réduit de démarrage'],
                'descriptions' => ['it' => 'Royalty 6,5% fino a 60.000 CHF, 5,5% oltre; fondo marketing 2%; minimo garantito di 2.000 CHF; nei primi 4 mesi la royalty è ridotta del 50%. Prezzi consigliati non vincolanti (CO / LCart).', 'en' => 'Royalty 6.5% up to CHF 60,000, 5.5% above; marketing fund 2%; minimum guarantee of CHF 2,000; in the first 4 months the royalty is reduced by 50%. Recommended, non-binding prices (CO / LCart).', 'de' => 'Royalty 6,5 % bis CHF 60.000, darüber 5,5 %; Marketingfonds 2 %; Mindestgarantie von CHF 2.000; in den ersten 4 Monaten ist die Royalty um 50 % reduziert. Unverbindliche Preisempfehlungen (OR / KG).', 'fr' => 'Redevance 6,5 % jusqu\'à CHF 60 000, 5,5 % au-delà ; fonds marketing 2 % ; minimum garanti de CHF 2 000 ; pendant les 4 premiers mois la redevance est réduite de 50 %. Prix recommandés non contraignants (CO / LCart).'],
                'royalty_tiers' => [['from' => 0, 'to' => 60000, 'rate' => 6.5], ['from' => 60000, 'to' => null, 'rate' => 5.5]],
                'marketing_tiers' => [['from' => 0, 'to' => null, 'rate' => 2]],
                'minimum_guarantee' => 2000, 'startup_months' => 4, 'startup_mode' => 'reduced_rate', 'startup_reduction_percent' => 50, 'ramp_start_percent' => 0, 'startup_applies_to_marketing' => false, 'currency' => 'CHF',
            ],
        ];

        foreach ($presets as $preset) {
            if (! FranchiseFormula::query()->where('code', $preset['code'])->exists()) {
                FranchiseFormula::createGlobal([...$preset, 'is_preset' => true, 'is_active' => true]);
            }
        }
    }

    private function auditTemplates(): void
    {
        // category => [code, [it, en, de, fr], weight, critical]
        $criteria = [
            ['SIGN-EXTERIOR', 'signage', ['Insegna esterna integra, pulita e illuminata', 'Exterior sign intact, clean and lit', 'Aussenschild intakt, sauber und beleuchtet', 'Enseigne extérieure intacte, propre et éclairée'], 2, true],
            ['SIGN-WINDOW', 'signage', ['Vetrina e grafiche promozionali aggiornate', 'Window and promotional graphics up to date', 'Schaufenster und Werbegrafiken aktuell', 'Vitrine et visuels promotionnels à jour'], 1, false],
            ['SIGN-LEGAL', 'signage', ['Orari, prezzi e avvisi di legge esposti', 'Opening hours, prices and legal notices displayed', 'Öffnungszeiten, Preise und gesetzliche Hinweise ausgehängt', 'Horaires, prix et mentions légales affichés'], 1, false],
            ['CLEAN-FLOOR', 'cleanliness', ['Pavimenti e ingresso puliti', 'Floors and entrance clean', 'Böden und Eingang sauber', 'Sols et entrée propres'], 2, false],
            ['CLEAN-SHELVES', 'cleanliness', ['Scaffali ed espositori privi di polvere', 'Shelves and displays free of dust', 'Regale und Displays staubfrei', 'Rayons et présentoirs sans poussière'], 1, false],
            ['CLEAN-STAFFAREA', 'cleanliness', ['Retrobottega e spazi del personale in ordine', 'Back room and staff areas tidy', 'Lager und Personalbereiche ordentlich', 'Réserve et espaces du personnel rangés'], 1, false],
            ['VM-FRONT', 'visual_merchandising', ['Prodotti di punta esposti secondo il planogramma', 'Key products displayed per the planogram', 'Schlüsselprodukte gemäss Planogramm präsentiert', 'Produits phares exposés selon le planogramme'], 2, false],
            ['VM-SEASONAL', 'visual_merchandising', ['Allestimento stagionale e promozioni attivi', 'Seasonal display and promotions in place', 'Saisonale Präsentation und Aktionen vorhanden', 'Mise en scène saisonnière et promotions en place'], 1, false],
            ['VM-LIGHTING', 'visual_merchandising', ['Illuminazione e cartellonistica prodotto corrette', 'Lighting and product signage correct', 'Beleuchtung und Produktbeschilderung korrekt', 'Éclairage et signalétique produit corrects'], 1, false],
            ['LAYOUT-FLOW', 'layout', ['Percorso cliente e layout conformi al format', 'Customer flow and layout compliant with the format', 'Kundenlauf und Layout formatkonform', 'Parcours client et agencement conformes au concept'], 2, false],
            ['LAYOUT-SAFETY', 'layout', ['Uscite di sicurezza libere e accessibilità', 'Emergency exits clear and accessibility', 'Notausgänge frei und Barrierefreiheit', 'Issues de secours dégagées et accessibilité'], 2, true],
            ['LAYOUT-CHECKOUT', 'layout', ['Area cassa ordinata con registratore telematico attivo', 'Checkout area tidy with the fiscal register working', 'Kassenbereich ordentlich, Fiskalkasse in Betrieb', 'Zone de caisse rangée, caisse fiscale en service'], 1, false],
            ['STOCK-AVAIL', 'stock_pricing', ['Disponibilità dell\'assortimento obbligatorio', 'Availability of the mandatory range', 'Verfügbarkeit des Pflichtsortiments', 'Disponibilité de l\'assortiment obligatoire'], 2, false],
            ['STOCK-PRICE', 'stock_pricing', ['Prezzi a scaffale coerenti con il listino consigliato', 'Shelf prices consistent with the recommended price list', 'Regalpreise entsprechen der empfohlenen Preisliste', 'Prix en rayon conformes au tarif recommandé'], 1, false],
            ['STOCK-EXPIRY', 'stock_pricing', ['Scadenze e rotazione delle scorte rispettate', 'Expiry dates and stock rotation respected', 'Verfallsdaten und Lagerumschlag eingehalten', 'Dates de péremption et rotation des stocks respectées'], 2, true],
        ];

        $build = static fn (array $rows): array => array_map(static fn (array $row): array => [
            'code' => $row[0], 'category' => $row[1], 'labels' => ['it' => $row[2][0], 'en' => $row[2][1], 'de' => $row[2][2], 'fr' => $row[2][3]], 'weight' => $row[3], 'max_score' => 5, 'critical' => $row[4],
        ], $rows);

        $templates = [
            ['code' => 'RETAIL-STANDARD', 'labels' => ['it' => 'Audit completo del punto vendita', 'en' => 'Full store audit', 'de' => 'Vollständiges Standort-Audit', 'fr' => 'Audit complet du point de vente'], 'criteria' => $build($criteria), 'pass_threshold_percent' => 80],
            ['code' => 'RETAIL-FLASH', 'labels' => ['it' => 'Visita lampo (cinque criteri chiave)', 'en' => 'Flash visit (five key criteria)', 'de' => 'Kurzbesuch (fünf Schlüsselkriterien)', 'fr' => 'Visite éclair (cinq critères clés)'], 'criteria' => $build(array_filter($criteria, static fn (array $row): bool => in_array($row[0], ['SIGN-EXTERIOR', 'CLEAN-FLOOR', 'VM-FRONT', 'LAYOUT-SAFETY', 'STOCK-PRICE'], true))), 'pass_threshold_percent' => 75],
        ];

        foreach ($templates as $template) {
            if (! AuditTemplate::query()->where('code', $template['code'])->exists()) {
                AuditTemplate::createGlobal([...$template, 'criteria' => array_values($template['criteria']), 'is_active' => true]);
            }
        }
    }

    private function benchmarkDimensions(): void
    {
        $dimensions = [
            ['lfl_growth', 'percent', true, 1, ['Crescita like-for-like', 'Like-for-like growth', 'Like-for-like-Wachstum', 'Croissance à périmètre constant'], ['Variazione del venduto netto rispetto allo stesso mese dell\'anno precedente, a parità di punto vendita aperto.', 'Change of net sales against the same month of the previous year, for a store open in both periods.', 'Veränderung des Nettoumsatzes gegenüber dem gleichen Monat des Vorjahres, bei in beiden Zeiträumen geöffnetem Standort.', 'Variation du chiffre d\'affaires net par rapport au même mois de l\'année précédente, à point de vente ouvert aux deux périodes.']],
            ['avg_ticket', 'currency', true, 2, ['Scontrino medio', 'Average ticket', 'Durchschnittlicher Bon', 'Ticket moyen'], ['Venduto netto diviso per il numero di scontrini del mese.', 'Net sales divided by the number of tickets of the month.', 'Nettoumsatz geteilt durch die Anzahl Bons des Monats.', 'Chiffre d\'affaires net divisé par le nombre de tickets du mois.']],
            ['conversion_rate', 'percent', true, 3, ['Tasso di conversione', 'Conversion rate', 'Konversionsrate', 'Taux de conversion'], ['Scontrini emessi sul totale dei visitatori contati all\'ingresso.', 'Tickets issued over the visitors counted at the entrance.', 'Ausgestellte Bons im Verhältnis zu den am Eingang gezählten Besuchern.', 'Tickets émis sur le total des visiteurs comptés à l\'entrée.']],
            ['stock_rotation', 'ratio', true, 4, ['Rotazione delle scorte', 'Stock rotation', 'Lagerumschlag', 'Rotation des stocks'], ['Costo del venduto annualizzato diviso per il valore medio delle scorte.', 'Annualised cost of goods sold divided by the average stock value.', 'Annualisierte Warenkosten geteilt durch den durchschnittlichen Lagerwert.', 'Coût des marchandises vendues annualisé divisé par la valeur moyenne des stocks.']],
            ['net_sell_out', 'currency', true, 5, ['Sell-out netto', 'Net sell-out', 'Netto-Sell-out', 'Sell-out net'], ['Venduto netto del mese registrato da casse e POS.', 'Net sales of the month recorded by the registers and the POS.', 'Von Kassen und POS erfasster Nettoumsatz des Monats.', 'Chiffre d\'affaires net du mois enregistré par les caisses et le TPE.']],
        ];

        foreach ($dimensions as [$code, $unit, $higher, $order, $labels, $descriptions]) {
            if (! BenchmarkDimension::query()->where('code', $code)->exists()) {
                BenchmarkDimension::createGlobal([
                    'code' => $code, 'unit' => $unit, 'higher_is_better' => $higher, 'sort_order' => $order,
                    'labels' => ['it' => $labels[0], 'en' => $labels[1], 'de' => $labels[2], 'fr' => $labels[3]],
                    'descriptions' => ['it' => $descriptions[0], 'en' => $descriptions[1], 'de' => $descriptions[2], 'fr' => $descriptions[3]],
                ]);
            }
        }
    }
}
