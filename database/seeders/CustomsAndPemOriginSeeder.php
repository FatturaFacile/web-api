<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CustomsAndPemOriginSeeder extends Seeder
{
    /**
     * Seed free trade agreements (PEM / CH-EU area) with official legal templates
     * for invoice origin declarations (IT/DE/FR/EN) and an initial set of HS/CN/TARIC codes.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedFreeTradeAgreements();
            $this->seedInitialTaricCodes();
        });
    }

    private function seedFreeTradeAgreements(): void
    {
        $now = now();

        $agreements = [
            [
                'code' => 'CH-EU-1972',
                'name' => [
                    'it' => 'Accordo di libero scambio Svizzera - Unione Europea (Convenzione PEM)',
                    'de' => 'Freihandelsabkommen Schweiz - Europäische Union (PEM-Übereinkommen)',
                    'fr' => 'Accord de libre-échange Suisse - Union européenne (Convention PEM)',
                    'en' => 'Free Trade Agreement Switzerland - European Union (PEM Convention)',
                ],
                'partner_countries' => [
                    'CH', 'IT', 'DE', 'FR', 'AT', 'ES', 'PT', 'NL', 'BE', 'LU',
                    'IE', 'DK', 'SE', 'FI', 'PL', 'CZ', 'SK', 'HU', 'SI', 'HR',
                    'RO', 'BG', 'GR', 'CY', 'MT', 'EE', 'LV', 'LT',
                ],
                'value_threshold_eur' => 6000.00,
                'value_threshold_chf' => 10300.00,
                'proof_types' => ['INVOICE_DECLARATION', 'EUR1', 'EUR_MED'],
                'supports_approved_exporter' => true,
                'supports_rex' => false,
                'declaration_templates' => [
                    'it' => "L'esportatore delle merci contemplate nel presente documento (autorizzazione doganale n. {customs_auth_number}) dichiara che, salvo indicazione contraria, le merci sono di origine preferenziale {preferential_origin}.",
                    'de' => 'Der Ausführer (Ermächtigter Ausführer; Bewilligungs-Nr. {customs_auth_number}) der Waren, auf die sich dieses Handelspapier bezieht, erklärt, dass diese Waren, soweit nicht anders angegeben, präferenzbegünstigte {preferential_origin}-Ursprungswaren sind.',
                    'fr' => "L'exportateur des produits couverts par le présent document (autorisation douanière n° {customs_auth_number}) déclare que, sauf indication claire du contraire, ces produits ont l'origine préférentielle {preferential_origin}.",
                    'en' => 'The exporter of the products covered by this document (customs authorization No {customs_auth_number}) declares that, except where otherwise clearly indicated, these products are of {preferential_origin} preferential origin.',
                ],
                'declaration_templates_no_auth' => [
                    'it' => "L'esportatore delle merci contemplate nel presente documento dichiara che, salvo indicazione contraria, le merci sono di origine preferenziale {preferential_origin}.",
                    'de' => 'Der Ausführer der Waren, auf die sich dieses Handelspapier bezieht, erklärt, dass diese Waren, soweit nicht anders angegeben, präferenzbegünstigte {preferential_origin}-Ursprungswaren sind.',
                    'fr' => "L'exportateur des produits couverts par le présent document déclare que, sauf indication claire du contraire, ces produits ont l'origine préférentielle {preferential_origin}.",
                    'en' => 'The exporter of the products covered by this document declares that, except where otherwise clearly indicated, these products are of {preferential_origin} preferential origin.',
                ],
                'notes' => 'Threshold without customs authorization: 6,000 EUR (EU) / 10,300 CHF (Switzerland). Above this threshold, an Approved Exporter authorization number or EUR.1 / EUR-MED certificate is required.',
                'is_active' => true,
            ],
            [
                'code' => 'PEM-REVISED-2025',
                'name' => [
                    'it' => 'Convenzione Regionale Paneuromediterranea (PEM - Regole Rivedute)',
                    'de' => 'Regionales Übereinkommen über Pan-Europa-Mittelmeer-Präferenzursprungsregeln (Revidierte PEM-Regeln)',
                    'fr' => 'Convention régionale sur les règles d’origine préférentielles paneuroméditerranéennes (Règles PEM révisées)',
                    'en' => 'Regional Convention on Pan-Euro-Mediterranean Preferential Rules of Origin (Revised PEM Rules)',
                ],
                'partner_countries' => [
                    'CH', 'LI', 'NO', 'IS', 'IT', 'DE', 'FR', 'AT', 'ES', 'PT',
                    'NL', 'BE', 'LU', 'PL', 'CZ', 'SK', 'HU', 'SI', 'HR', 'RO',
                    'BG', 'GR', 'SE', 'FI', 'DK', 'TR', 'MA', 'TN', 'EG', 'JO',
                    'IL', 'PS', 'LB', 'AL', 'BA', 'ME', 'MK', 'RS', 'XK', 'MD', 'UA', 'GE',
                ],
                'value_threshold_eur' => 6000.00,
                'value_threshold_chf' => 10300.00,
                'proof_types' => ['INVOICE_DECLARATION', 'EUR1'],
                'supports_approved_exporter' => true,
                'supports_rex' => false,
                'declaration_templates' => [
                    'it' => "L'esportatore delle merci contemplate nel presente documento (autorizzazione doganale n. {customs_auth_number}) dichiara che, salvo indicazione contraria, le merci sono di origine preferenziale {preferential_origin} conformemente alle regole di origine transitorie.",
                    'de' => 'Der Ausführer (Ermächtigter Ausführer; Bewilligungs-Nr. {customs_auth_number}) der Waren, auf die sich dieses Handelspapier bezieht, erklärt, dass diese Waren, soweit nicht anders angegeben, präferenzbegünstigte {preferential_origin}-Ursprungswaren gemäß den Übergangsregeln sind.',
                    'fr' => "L'exportateur des produits couverts par le présent document (autorisation douanière n° {customs_auth_number}) déclare que, sauf indication claire du contraire, ces produits ont l'origine préférentielle {preferential_origin} selon les règles transitoires.",
                    'en' => 'The exporter of the products covered by this document (customs authorization No {customs_auth_number}) declares that, except where otherwise clearly indicated, these products are of {preferential_origin} preferential origin according to the transitional rules of origin.',
                ],
                'declaration_templates_no_auth' => [
                    'it' => "L'esportatore delle merci contemplate nel presente documento dichiara che, salvo indicazione contraria, le merci sono di origine preferenziale {preferential_origin} conformemente alle regole di origine transitorie.",
                    'de' => 'Der Ausführer der Waren, auf die sich dieses Handelspapier bezieht, erklärt, dass diese Waren, soweit nicht anders angegeben, präferenzbegünstigte {preferential_origin}-Ursprungswaren gemäß den Übergangsregeln sind.',
                    'fr' => "L'exportateur des produits couverts par le présent document déclare que, sauf indication claire du contraire, ces produits ont l'origine préférentielle {preferential_origin} selon les règles transitoires.",
                    'en' => 'The exporter of the products covered by this document declares that, except where otherwise clearly indicated, these products are of {preferential_origin} preferential origin according to the transitional rules of origin.',
                ],
                'notes' => 'Includes the reference to revised/transitional PEM rules when required by the applicable customs corridor.',
                'is_active' => true,
            ],
            [
                'code' => 'EFTA-CONVENTION',
                'name' => [
                    'it' => 'Convenzione AELS / EFTA (Svizzera, Liechtenstein, Norvegia, Islanda)',
                    'de' => 'EFTA-Übereinkommen (Schweiz, Liechtenstein, Norwegen, Island)',
                    'fr' => 'Convention AELE (Suisse, Liechtenstein, Norvège, Islande)',
                    'en' => 'EFTA Convention (Switzerland, Liechtenstein, Norway, Iceland)',
                ],
                'partner_countries' => ['CH', 'LI', 'NO', 'IS'],
                'value_threshold_eur' => 6000.00,
                'value_threshold_chf' => 10300.00,
                'proof_types' => ['INVOICE_DECLARATION', 'EUR1'],
                'supports_approved_exporter' => true,
                'supports_rex' => false,
                'declaration_templates' => [
                    'it' => "L'esportatore delle merci contemplate nel presente documento (autorizzazione doganale n. {customs_auth_number}) dichiara che, salvo indicazione contraria, le merci sono di origine preferenziale {preferential_origin}.",
                    'de' => 'Der Ausführer (Ermächtigter Ausführer; Bewilligungs-Nr. {customs_auth_number}) der Waren, auf die sich dieses Handelspapier bezieht, erklärt, dass diese Waren, soweit nicht anders angegeben, präferenzbegünstigte {preferential_origin}-Ursprungswaren sind.',
                    'fr' => "L'exportateur des produits couverts par le présent document (autorisation douanière n° {customs_auth_number}) déclare que, sauf indication claire du contraire, ces produits ont l'origine préférentielle {preferential_origin}.",
                    'en' => 'The exporter of the products covered by this document (customs authorization No {customs_auth_number}) declares that, except where otherwise clearly indicated, these products are of {preferential_origin} preferential origin.',
                ],
                'declaration_templates_no_auth' => [
                    'it' => "L'esportatore delle merci contemplate nel presente documento dichiara che, salvo indicazione contraria, le merci sono di origine preferenziale {preferential_origin}.",
                    'de' => 'Der Ausführer der Waren, auf die sich dieses Handelspapier bezieht, erklärt, dass diese Waren, soweit nicht anders angegeben, präferenzbegünstigte {preferential_origin}-Ursprungswaren sind.',
                    'fr' => "L'exportateur des produits couverts par le présent document déclare que, sauf indication claire du contraire, ces produits ont l'origine préférentielle {preferential_origin}.",
                    'en' => 'The exporter of the products covered by this document declares that, except where otherwise clearly indicated, these products are of {preferential_origin} preferential origin.',
                ],
                'notes' => 'Internal trade agreement among EFTA member states (including the CH-LI customs union).',
                'is_active' => true,
            ],
            [
                'code' => 'CH-UK-FTA',
                'name' => [
                    'it' => 'Accordo commerciale Svizzera - Regno Unito (Post-Brexit)',
                    'de' => 'Handelsabkommen Schweiz - Vereinigtes Königreich',
                    'fr' => 'Accord commercial Suisse - Royaume-Uni',
                    'en' => 'Switzerland - United Kingdom Trade Agreement',
                ],
                'partner_countries' => ['CH', 'LI', 'GB'],
                'value_threshold_eur' => 6000.00,
                'value_threshold_chf' => 10300.00,
                'proof_types' => ['INVOICE_DECLARATION', 'EUR1'],
                'supports_approved_exporter' => true,
                'supports_rex' => false,
                'declaration_templates' => [
                    'it' => "L'esportatore delle merci contemplate nel presente documento (autorizzazione doganale n. {customs_auth_number}) dichiara che, salvo indicazione contraria, le merci sono di origine preferenziale {preferential_origin}.",
                    'de' => 'Der Ausführer (Ermächtigter Ausführer; Bewilligungs-Nr. {customs_auth_number}) der Waren, auf die sich dieses Handelspapier bezieht, erklärt, dass diese Waren, soweit nicht anders angegeben, präferenzbegünstigte {preferential_origin}-Ursprungswaren sind.',
                    'fr' => "L'exportateur des produits couverts par le présent document (autorisation douanière n° {customs_auth_number}) déclare que, sauf indication claire du contraire, ces produits ont l'origine préférentielle {preferential_origin}.",
                    'en' => 'The exporter of the products covered by this document (customs authorization No {customs_auth_number}) declares that, except where otherwise clearly indicated, these products are of {preferential_origin} preferential origin.',
                ],
                'declaration_templates_no_auth' => [
                    'it' => "L'esportatore delle merci contemplate nel presente documento dichiara che, salvo indicazione contraria, le merci sono di origine preferenziale {preferential_origin}.",
                    'de' => 'Der Ausführer der Waren, auf die sich dieses Handelspapier bezieht, erklärt, dass diese Waren, soweit nicht anders angegeben, präferenzbegünstigte {preferential_origin}-Ursprungswaren sind.',
                    'fr' => "L'exportateur des produits couverts par le présent document déclare que, sauf indication claire du contraire, ces produits ont l'origine préférentielle {preferential_origin}.",
                    'en' => 'The exporter of the products covered by this document declares that, except where otherwise clearly indicated, these products are of {preferential_origin} preferential origin.',
                ],
                'notes' => 'Bilateral CH-UK rules allowing EU diagonal cumulation subject to protocol conditions.',
                'is_active' => true,
            ],
            [
                'code' => 'EU-UK-TCA',
                'name' => [
                    'it' => 'Accordo sugli scambi e la cooperazione UE - Regno Unito (TCA - Sistema REX)',
                    'de' => 'Handels- und Kooperationsabkommen EU - Vereinigtes Königreich (TCA - REX)',
                    'fr' => 'Accord de commerce et de coopération UE - Royaume-Uni (TCA - REX)',
                    'en' => 'EU - United Kingdom Trade and Cooperation Agreement (TCA - REX System)',
                ],
                'partner_countries' => [
                    'GB', 'IT', 'DE', 'FR', 'AT', 'ES', 'PT', 'NL', 'BE', 'LU',
                    'IE', 'DK', 'SE', 'FI', 'PL', 'CZ', 'SK', 'HU', 'SI', 'HR',
                    'RO', 'BG', 'GR', 'CY', 'MT', 'EE', 'LV', 'LT',
                ],
                'value_threshold_eur' => 6000.00,
                'value_threshold_chf' => null,
                'proof_types' => ['STATEMENT_ON_ORIGIN_REX'],
                'supports_approved_exporter' => false,
                'supports_rex' => true,
                'declaration_templates' => [
                    'it' => "L'esportatore delle merci contemplate nel presente documento (numero di riferimento dell'esportatore {customs_auth_number}) dichiara che, salvo indicazione contraria, le merci sono di origine preferenziale {preferential_origin}.",
                    'de' => 'Der Ausführer (Referenznummer des Ausführers {customs_auth_number}) der Waren, auf die sich dieses Handelspapier bezieht, erklärt, dass diese Waren, soweit nicht anders angegeben, präferenzbegünstigte {preferential_origin}-Ursprungswaren sind.',
                    'fr' => "L'exportateur des produits couverts par le présent document (numéro de référence de l'exportateur {customs_auth_number}) déclare que, sauf indication claire du contraire, ces produits ont l'origine préférentielle {preferential_origin}.",
                    'en' => 'The exporter of the products covered by this document (Exporter Reference No {customs_auth_number}) declares that, except where otherwise clearly indicated, these products are of {preferential_origin} preferential origin.',
                ],
                'declaration_templates_no_auth' => [
                    'it' => "L'esportatore delle merci contemplate nel presente documento dichiara che, salvo indicazione contraria, le merci sono di origine preferenziale {preferential_origin}.",
                    'de' => 'Der Ausführer der Waren, auf die sich dieses Handelspapier bezieht, erklärt, dass diese Waren, soweit nicht anders angegeben, präferenzbegünstigte {preferential_origin}-Ursprungswaren sind.',
                    'fr' => "L'exportateur des produits couverts par le présent document déclare que, sauf indication claire du contraire, ces produits ont l'origine préférentielle {preferential_origin}.",
                    'en' => 'The exporter of the products covered by this document declares that, except where otherwise clearly indicated, these products are of {preferential_origin} preferential origin.',
                ],
                'notes' => 'For EU -> UK exports exceeding 6,000 EUR, a Registered Exporter (REX) number is mandatory.',
                'is_active' => true,
            ],
        ];

        foreach ($agreements as $agreement) {
            $this->upsertByCode('free_trade_agreements',
                $agreement['code'],
                [
                    'code' => $agreement['code'],
                    'name' => json_encode($agreement['name'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'partner_countries' => json_encode($agreement['partner_countries'], JSON_THROW_ON_ERROR),
                    'value_threshold_eur' => $agreement['value_threshold_eur'],
                    'value_threshold_chf' => $agreement['value_threshold_chf'],
                    'proof_types' => json_encode($agreement['proof_types'], JSON_THROW_ON_ERROR),
                    'supports_approved_exporter' => $agreement['supports_approved_exporter'],
                    'supports_rex' => $agreement['supports_rex'],
                    'declaration_templates' => json_encode($agreement['declaration_templates'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'declaration_templates_no_auth' => json_encode($agreement['declaration_templates_no_auth'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'notes' => $agreement['notes'],
                    'is_active' => $agreement['is_active'],
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function seedInitialTaricCodes(): void
    {
        $now = now();

        $taricCodes = [
            // --- Horology & Precision Micromechanics (Typical CH export) ---
            [
                'code' => '91012100',
                'level' => 'CN8',
                'chapter' => '91',
                'description' => [
                    'it' => 'Orologi da polso, a carica automatica, con cassa di metalli preziosi',
                    'de' => 'Armbanduhren mit automatischem Aufzug, mit Gehäuse aus Edelmetall',
                    'fr' => 'Montres-bracelets, à remontage automatique, avec boîte en métaux précieux',
                    'en' => 'Wrist-watches, with automatic winding, with case of precious metal',
                ],
                'supplementary_unit' => 'p/st',
                'duty_rate_notes' => 'Duty-free within PEM/CH-EU area with proof of preferential origin (Swiss Made / EU).',
            ],
            [
                'code' => '91022100',
                'level' => 'CN8',
                'chapter' => '91',
                'description' => [
                    'it' => 'Orologi da polso, a carica automatica, con cassa in acciaio o altri metalli comuni',
                    'de' => 'Armbanduhren mit automatischem Aufzug, mit Gehäuse aus unedlen Metallen',
                    'fr' => 'Montres-bracelets, à remontage automatique, avec boîte en métaux communs',
                    'en' => 'Wrist-watches, with automatic winding, with case of base metal',
                ],
                'supplementary_unit' => 'p/st',
                'duty_rate_notes' => 'Duty-free with PEM preferential origin.',
            ],

            // --- Mechanics, Industrial Automation & Tooling (Typical CH-IT trade) ---
            [
                'code' => '84818099',
                'level' => 'CN8',
                'chapter' => '84',
                'description' => [
                    'it' => 'Rubinetti, valvole e apparecchi simili per tubazioni, caldaie, serbatoi (altri)',
                    'de' => 'Armaturen und ähnliche Apparate für Rohrleitungen, Dampfkessel, Sammelbehälter (andere)',
                    'fr' => 'Articles de robinetterie et organes similaires pour tuyauteries, chaudières, réservoirs (autres)',
                    'en' => 'Taps, cocks, valves and similar appliances for pipes, boiler shells, tanks (other)',
                ],
                'supplementary_unit' => null,
                'duty_rate_notes' => 'PEM list rule: change of tariff heading (CTH) or max 40% non-originating materials.',
            ],
            [
                'code' => '85371098',
                'level' => 'CN8',
                'chapter' => '85',
                'description' => [
                    'it' => 'Quadri, pannelli, mensole, banchi e armadi per il comando elettrico o la distribuzione (<= 1000 V)',
                    'de' => 'Tafeln, Felder, Konsolen, Pulte, Schränke für elektrische Steuerung oder Stromverteilung (<= 1000 V)',
                    'fr' => 'Tableaux, panneaux, consoles, pupitres, armoires pour commande ou distribution électrique (<= 1000 V)',
                    'en' => 'Boards, panels, consoles, desks, cabinets for electric control or distribution of electricity (<= 1000 V)',
                ],
                'supplementary_unit' => null,
                'duty_rate_notes' => 'Common in Italy-Switzerland industrial automation.',
            ],
            [
                'code' => '90318080',
                'level' => 'CN8',
                'chapter' => '90',
                'description' => [
                    'it' => 'Strumenti, apparecchi e macchine di misura o di controllo, non nominati altrove',
                    'de' => 'Instrumente, Apparate und Maschinen zum Messen oder Prüfen, anderweit nicht genannt',
                    'fr' => 'Instruments, appareils et machines de mesure ou de contrôle, non dénommés ailleurs',
                    'en' => 'Measuring or checking instruments, appliances and machines, not elsewhere specified',
                ],
                'supplementary_unit' => null,
                'duty_rate_notes' => 'Check for potential Dual-Use export restrictions outside the EU.',
            ],

            // --- Fine Agri-Food Products (Typical IT -> CH and CH -> IT trade) ---
            [
                'code' => '09012100',
                'level' => 'CN8',
                'chapter' => '09',
                'description' => [
                    'it' => 'Caffè torrefatto, non decaffeinato',
                    'de' => 'Kaffee, geröstet, nicht entkoffeiniert',
                    'fr' => 'Café torréfié, non décaféiné',
                    'en' => 'Coffee, roasted, not decaffeinated',
                ],
                'supplementary_unit' => null,
                'duty_rate_notes' => 'Processed agricultural product: verify specific protocols for residual CH agricultural duties.',
            ],
            [
                'code' => '15092000',
                'level' => 'CN8',
                'chapter' => '15',
                'description' => [
                    'it' => 'Olio extra vergine di oliva',
                    'de' => 'Natives Olivenöl extra',
                    'fr' => "Huile d'olive vierge extra",
                    'en' => 'Extra virgin olive oil',
                ],
                'supplementary_unit' => 'l',
                'duty_rate_notes' => 'Wholly obtained preferential origin (olives harvested and pressed in the partner country).',
            ],
            [
                'code' => '04069021',
                'level' => 'CN8',
                'chapter' => '04',
                'description' => [
                    'it' => 'Formaggi a pasta dura (es. Cheddar, Gruyère, Parmigiano Reggiano / Grana Padano)',
                    'de' => 'Hartkäse (z.B. Cheddar, Gruyère, Parmigiano Reggiano / Grana Padano)',
                    'fr' => 'Fromages à pâte dure (ex. Cheddar, Gruyère, Parmigiano Reggiano / Grana Padano)',
                    'en' => 'Hard cheeses (e.g. Cheddar, Gruyère, Parmigiano Reggiano / Grana Padano)',
                ],
                'supplementary_unit' => null,
                'duty_rate_notes' => 'Subject to the CH-EU bilateral agricultural annex.',
            ],
            [
                'code' => '18063210',
                'level' => 'CN8',
                'chapter' => '18',
                'description' => [
                    'it' => 'Cioccolata e altre preparazioni alimentari contenenti cacao, in tavolette o bastoncini, non ripiene, con aggiunta di cereali, frutta o noci',
                    'de' => 'Schokolade und andere kakaohaltige Lebensmittelzubereitungen, in Tafeln oder Riegeln, nicht gefüllt, mit Zusatz von Getreide, Früchten oder Nüssen',
                    'fr' => 'Chocolat et autres préparations alimentaires contenant du cacao, en tablettes ou bâtons, non fourrés, avec addition de céréales, fruits ou noix',
                    'en' => 'Chocolate and other food preparations containing cocoa, in blocks, slabs or bars, not filled, with added cereal, fruit or nuts',
                ],
                'supplementary_unit' => null,
                'duty_rate_notes' => 'Protocol No. 2 of the CH-EU Agreement (processed agricultural products).',
            ],
            [
                'code' => '22042179',
                'level' => 'CN8',
                'chapter' => '22',
                'description' => [
                    'it' => 'Vini di uve fresche in recipienti di capacità inferiore o uguale a 2 litri (DOP/IGP)',
                    'de' => 'Wein aus frischen Weintrauben in Behältnissen mit einem Inhalt von 2 l oder weniger (g.U./g.g.A.)',
                    'fr' => 'Vins de raisins frais en récipients d’une contenance n’excédant pas 2 litres (AOP/IGP)',
                    'en' => 'Wine of fresh grapes in containers holding 2 litres or less (PDO/PGI)',
                ],
                'supplementary_unit' => 'l',
                'duty_rate_notes' => 'Subject to tariff quotas and consumption taxes / excise duties upon import.',
            ],

            // --- Fashion, Textiles & Leather Goods (Typical IT export) ---
            [
                'code' => '42022100',
                'level' => 'CN8',
                'chapter' => '42',
                'description' => [
                    'it' => 'Borse a mano, anche con tracolla o senza manici, con superficie esterna di cuoio naturale o ricostituito',
                    'de' => 'Handtaschen, auch mit Schulterriemen oder ohne Griff, mit Außenseite aus Leder oder rekonstituiertem Leder',
                    'fr' => 'Sacs à main, même à bandoulière, y compris ceux sans poignée, à surface extérieure en cuir naturel ou reconstitué',
                    'en' => 'Handbags, whether or not with shoulder strap, including those without handle, with outer surface of leather or composition leather',
                ],
                'supplementary_unit' => 'p/st',
                'duty_rate_notes' => 'Duty-free with Made in Italy preferential origin (PEM).',
            ],
            [
                'code' => '62031100',
                'level' => 'CN8',
                'chapter' => '62',
                'description' => [
                    'it' => 'Vestiti (abiti) per uomo o ragazzo, di lana o di peli fini',
                    'de' => 'Anzüge für Männer oder Knaben, aus Wolle oder feinen Tierhaaren',
                    'fr' => 'Costumes ou complets pour hommes ou garçonnets, de laine ou de poils fins',
                    'en' => 'Men’s or boys’ suits, of wool or fine animal hair',
                ],
                'supplementary_unit' => 'p/st',
                'duty_rate_notes' => 'Double transformation rule (spinning + making-up) within the PEM territory.',
            ],

            // --- Pharmaceuticals, Chemicals & Medical Devices ---
            [
                'code' => '30049000',
                'level' => 'CN8',
                'chapter' => '30',
                'description' => [
                    'it' => 'Medicamenti costituiti da prodotti miscelati o non miscelati, preparati per scopi terapeutici o profilattici, presentati sotto forma di dosi',
                    'de' => 'Arzneiwaren, aus gemischten oder ungemischten Erzeugnissen zu therapeutischen oder prophylaktischen Zwecken, dosiert',
                    'fr' => 'Médicaments constitués par des produits mélangés ou non mélangés, préparés à des fins thérapeutiques ou prophylactiques, présentés sous forme de doses',
                    'en' => 'Medicaments consisting of mixed or unmixed products for therapeutic or prophylactic uses, put up in measured doses',
                ],
                'supplementary_unit' => null,
                'duty_rate_notes' => 'Key CH-IT export sector; subject to Swissmedic / AIFA health authorizations.',
            ],
            [
                'code' => '90213100',
                'level' => 'CN8',
                'chapter' => '90',
                'description' => [
                    'it' => 'Protesi articolari',
                    'de' => 'Gelenkprothesen',
                    'fr' => 'Prothèses articulaires',
                    'en' => 'Artificial joints',
                ],
                'supplementary_unit' => null,
                'duty_rate_notes' => 'Medical device; verify CE / Swissdamed marking compliance.',
            ],

            // --- 10-digit TARIC Examples (Specific EU Community Subheadings) ---
            [
                'code' => '8471300000',
                'level' => 'TARIC10',
                'chapter' => '84',
                'description' => [
                    'it' => 'Macchine automatiche per l’elaborazione dell’informazione, portatili, di peso non superiore a 10 kg (laptop, tablet)',
                    'de' => 'Tragbare automatische Datenverarbeitungsmaschinen mit einem Gewicht von nicht mehr als 10 kg (Laptops, Tablets)',
                    'fr' => 'Machines automatiques de traitement de l’information, portatives, d’un poids n’excédant pas 10 kg (ordinateurs portables, tablettes)',
                    'en' => 'Portable automatic data-processing machines, weighing not more than 10 kg (laptops, tablets)',
                ],
                'supplementary_unit' => 'p/st',
                'duty_rate_notes' => 'Full 10-digit TARIC code; subject to VAT/duty assessment based on customs value.',
            ],
            [
                'code' => '7318159590',
                'level' => 'TARIC10',
                'chapter' => '73',
                'description' => [
                    'it' => 'Altre viti e bulloni filettati di ferro o di acciaio, con testa (esclusi quelli di acciaio inossidabile)',
                    'de' => 'Andere Schrauben und Bolzen mit Gewinde, aus Eisen oder Stahl, mit Kopf (ausgenommen aus nichtrostendem Stahl)',
                    'fr' => 'Autres vis et boulons filetés, en fer ou en acier, avec tête (à l’exclusion de ceux en acier inoxydable)',
                    'en' => 'Other threaded screws and bolts, of iron or steel, with heads (excluding stainless steel)',
                ],
                'supplementary_unit' => null,
                'duty_rate_notes' => 'Watch for potential EU anti-dumping duties on fasteners of non-preferential extra-PEM origin.',
            ],
        ];

        foreach ($taricCodes as $item) {
            $this->upsertByCode('customs_taric_codes',
                $item['code'],
                [
                    'code' => $item['code'],
                    'level' => $item['level'], // HS6 | CN8 | TARIC10
                    'chapter' => $item['chapter'],
                    'description' => json_encode($item['description'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'supplementary_unit' => $item['supplementary_unit'],
                    'duty_rate_notes' => $item['duty_rate_notes'],
                    'is_active' => true,
                    'updated_at' => $now,
                ]
            );
        }
    }

    /**
     * Idempotent write keyed by `code`: the primary key and creation timestamp are assigned once, so foreign keys
     * pointing at the row survive repeated seeding.
     *
     * @param  array<string, mixed>  $values
     */
    private function upsertByCode(string $table, string $code, array $values): void
    {
        $query = DB::table($table)->where('code', $code);

        if ($query->exists()) {
            $query->update($values);

            return;
        }

        DB::table($table)->insert([
            'id' => (string) Str::uuid7(),
            ...$values,
            'created_at' => $values['updated_at'],
        ]);
    }
}
