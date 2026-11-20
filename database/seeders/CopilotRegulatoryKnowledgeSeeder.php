<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Copilot\Services\KnowledgeIndexer;

/**
 * The global regulatory knowledge base of the Copilot, in Italian, German, French and English: Swiss (CO/OR, MWSTG/LTVA/LIVA,
 * GeBüV/Olc, Swiss SME chart of accounts, QR-bill, Swissdec ELM) and Italian (DPR 633/72, TUIR, OIC, SdI / FatturaPA, VAT
 * natures N1-N7, document types TD01-TD28) summaries. Each passage is a short orientation with its legal reference, not
 * legal advice. Idempotent: running it again updates the passages in place.
 */
class CopilotRegulatoryKnowledgeSeeder extends Seeder
{
    public function run(KnowledgeIndexer $indexer): void
    {
        $documents = [];

        foreach ($this->entries() as $entry) {
            foreach ($entry['text'] as $locale => $text) {
                $documents[] = [
                    'title' => $entry['title'],
                    'section_ref' => $entry['ref'],
                    'content' => $text,
                    'jurisdiction' => $entry['jurisdiction'],
                    'locale' => $locale,
                    'source_type' => 'regulatory',
                    'badge' => $entry['badge'],
                    'metadata' => ['source' => $entry['source']],
                ];
            }
        }

        $indexer->index(null, $documents);
    }

    /**
     * @return list<array{jurisdiction: string, source: string, title: string, ref: string, badge: string, text: array<string, string>}>
     */
    private function entries(): array
    {
        return [
            [
                'jurisdiction' => 'CH', 'source' => 'CO', 'title' => 'CO / OR – Bookkeeping', 'ref' => 'Art. 957–958f', 'badge' => 'CO Art. 957',
                'text' => [
                    'it' => "Secondo l'art. 957 del Codice delle obbligazioni (CO), le imprese iscritte nel registro di commercio devono tenere una contabilità e presentare i conti annuali; le ditte individuali e le società di persone con una cifra d'affari inferiore a CHF 500'000 possono limitarsi a entrate, uscite e situazione patrimoniale. L'art. 958f CO impone di conservare libri contabili e documenti giustificativi per dieci anni.",
                    'de' => "Nach Art. 957 des Obligationenrechts (OR) müssen im Handelsregister eingetragene Unternehmen Buch führen und Rechnung legen; Einzelunternehmen und Personengesellschaften mit weniger als CHF 500'000 Umsatz dürfen sich auf Einnahmen, Ausgaben und die Vermögenslage beschränken. Art. 958f OR verlangt, Geschäftsbücher und Buchungsbelege zehn Jahre aufzubewahren.",
                    'fr' => "Selon l'art. 957 du Code des obligations (CO), les entreprises inscrites au registre du commerce doivent tenir une comptabilité et présenter des comptes; les entreprises individuelles et sociétés de personnes réalisant moins de CHF 500'000 de chiffre d'affaires peuvent se limiter aux recettes, aux dépenses et à l'état du patrimoine. L'art. 958f CO impose de conserver les livres et les pièces comptables pendant dix ans.",
                    'en' => 'Under Art. 957 of the Swiss Code of Obligations (CO), companies entered in the commercial register must keep accounts and prepare financial statements; sole proprietorships and partnerships with turnover below CHF 500,000 may limit themselves to income, expenses and the asset position. Art. 958f CO requires books and accounting vouchers to be kept for ten years.',
                ],
            ],
            [
                'jurisdiction' => 'CH', 'source' => 'MWSTG', 'title' => 'LIVA / MWSTG / LTVA – VAT rates', 'ref' => 'Art. 25', 'badge' => 'LIVA Art. 25',
                'text' => [
                    'it' => "Secondo l'art. 25 della legge sull'IVA (LIVA), dal 1° gennaio 2024 l'aliquota normale è 8,1%, l'aliquota ridotta 2,6% (ad esempio generi alimentari, medicamenti, libri, giornali) e l'aliquota speciale per le prestazioni alberghiere 3,8%.",
                    'de' => 'Nach Art. 25 des Mehrwertsteuergesetzes (MWSTG) beträgt der Normalsatz seit dem 1. Januar 2024 8,1%, der reduzierte Satz 2,6% (zum Beispiel Lebensmittel, Medikamente, Bücher, Zeitungen) und der Sondersatz für Beherbergungsleistungen 3,8%.',
                    'fr' => "Selon l'art. 25 de la loi sur la TVA (LTVA), depuis le 1er janvier 2024 le taux normal est de 8,1%, le taux réduit de 2,6% (par exemple denrées alimentaires, médicaments, livres, journaux) et le taux spécial pour les prestations d'hébergement de 3,8%.",
                    'en' => 'Under Art. 25 of the Swiss VAT Act (MWSTG), since 1 January 2024 the standard rate is 8.1%, the reduced rate 2.6% (for example food, medicines, books, newspapers) and the special rate for accommodation services 3.8%.',
                ],
            ],
            [
                'jurisdiction' => 'CH', 'source' => 'MWSTG', 'title' => 'LIVA / MWSTG / LTVA – VAT registration', 'ref' => 'Art. 10', 'badge' => 'LIVA Art. 10',
                'text' => [
                    'it' => "Secondo l'art. 10 LIVA è assoggettata all'IVA l'impresa che realizza una cifra d'affari imponibile di almeno CHF 100'000 all'anno in Svizzera e all'estero; sotto questa soglia l'assoggettamento è facoltativo.",
                    'de' => "Nach Art. 10 MWSTG ist ein Unternehmen steuerpflichtig, wenn es jährlich einen steuerbaren Umsatz von mindestens CHF 100'000 im In- und Ausland erzielt; darunter ist die Unterstellung freiwillig.",
                    'fr' => "Selon l'art. 10 LTVA, une entreprise est assujettie à la TVA lorsqu'elle réalise un chiffre d'affaires imposable d'au moins CHF 100'000 par an en Suisse et à l'étranger; en dessous de ce seuil l'assujettissement est facultatif.",
                    'en' => 'Under Art. 10 MWSTG a business is liable for VAT when its taxable turnover in Switzerland and abroad reaches at least CHF 100,000 per year; below that threshold registration is voluntary.',
                ],
            ],
            [
                'jurisdiction' => 'CH', 'source' => 'GeBüV', 'title' => 'Olc / GeBüV – Keeping accounting records', 'ref' => 'Art. 3–9', 'badge' => 'Olc/GeBüV',
                'text' => [
                    'it' => "L'ordinanza sulla tenuta e conservazione dei libri di commercio (Olc) richiede che la contabilità sia integra, autentica, leggibile e consultabile per l'intero periodo di conservazione di dieci anni. I supporti elettronici devono impedire modifiche non rilevabili e le procedure devono essere documentate.",
                    'de' => 'Die Geschäftsbücherverordnung (GeBüV) verlangt, dass Geschäftsunterlagen während der zehnjährigen Aufbewahrungsfrist integer, echt, lesbar und zugänglich bleiben. Elektronische Datenträger müssen unbemerkte Änderungen ausschliessen, und die Verfahren sind zu dokumentieren.',
                    'fr' => "L'ordonnance concernant la tenue et la conservation des livres de comptes (Olico) exige que les documents comptables restent intègres, authentiques, lisibles et consultables pendant toute la durée de conservation de dix ans. Les supports électroniques doivent exclure toute modification non décelable et les procédures doivent être documentées.",
                    'en' => 'The Swiss ordinance on keeping and storing accounting records (GeBüV) requires records to remain complete, authentic, legible and accessible for the whole ten-year retention period. Electronic media must rule out undetectable changes, and the procedures must be documented.',
                ],
            ],
            [
                'jurisdiction' => 'CH', 'source' => 'KMU', 'title' => 'Piano dei conti KMU / Kontenrahmen KMU', 'ref' => 'Classes 1–9', 'badge' => 'Piano KMU',
                'text' => [
                    'it' => "Il piano dei conti svizzero per le PMI (Käfer) è organizzato in classi: 1 attivi, 2 passivi e capitale proprio, 3 ricavi di produzione e di prestazioni, 4 costi di materiale, merci e prestazioni di terzi, 5 costi del personale, 6 altri costi d'esercizio, 7 attività accessorie, 8 risultato straordinario, 9 chiusura.",
                    'de' => 'Der Schweizer Kontenrahmen KMU (Käfer) gliedert sich in Klassen: 1 Aktiven, 2 Passiven und Eigenkapital, 3 Betriebsertrag aus Lieferungen und Leistungen, 4 Aufwand für Material, Waren und Drittleistungen, 5 Personalaufwand, 6 sonstiger Betriebsaufwand, 7 betriebliche Nebenerfolge, 8 ausserordentlicher Erfolg, 9 Abschluss.',
                    'fr' => "Le plan comptable suisse pour PME (Käfer) est organisé en classes: 1 actifs, 2 passifs et capitaux propres, 3 produits d'exploitation, 4 charges de matériel, de marchandises et de prestations de tiers, 5 charges de personnel, 6 autres charges d'exploitation, 7 résultats annexes, 8 résultat extraordinaire, 9 clôture.",
                    'en' => 'The Swiss SME chart of accounts (Käfer) is organised in classes: 1 assets, 2 liabilities and equity, 3 operating revenue from goods and services, 4 expenses for material, goods and third-party services, 5 personnel expenses, 6 other operating expenses, 7 ancillary operations, 8 extraordinary result, 9 closing.',
                ],
            ],
            [
                'jurisdiction' => 'CH', 'source' => 'QR-bill', 'title' => 'QR-bill (Swiss Payment Standards)', 'ref' => 'SPC 0200', 'badge' => 'QR-Bill SPC 0200',
                'text' => [
                    'it' => 'La QR-fattura codifica i dati in un codice QR che inizia con «SPC», versione «0200» e codifica 1 (UTF-8). Con un QR-IBAN (IID da 30000 a 31999) serve un riferimento QR di 27 cifre con cifra di controllo modulo 10; con un IBAN normale si usa un riferimento del creditore (ISO 11649) oppure nessun riferimento. La valuta è CHF o EUR.',
                    'de' => 'Die QR-Rechnung codiert die Daten in einem QR-Code, der mit «SPC», Version «0200» und Zeichensatz 1 (UTF-8) beginnt. Bei einer QR-IBAN (IID 30000 bis 31999) ist eine 27-stellige QR-Referenz mit Modulo-10-Prüfziffer nötig; bei einer normalen IBAN dient eine Creditor Reference (ISO 11649) oder keine Referenz. Die Währung ist CHF oder EUR.',
                    'fr' => 'La facture QR code les données dans un code QR qui commence par «SPC», version «0200» et codage 1 (UTF-8). Avec un QR-IBAN (IID de 30000 à 31999) une référence QR de 27 chiffres avec clé de contrôle modulo 10 est requise; avec un IBAN normal on utilise une référence créancier (ISO 11649) ou aucune référence. La monnaie est CHF ou EUR.',
                    'en' => 'The QR-bill encodes its data in a QR code starting with "SPC", version "0200" and coding type 1 (UTF-8). With a QR-IBAN (IID 30000 to 31999) a 27-digit QR reference with a modulo 10 check digit is required; with a normal IBAN a creditor reference (ISO 11649) or no reference is used. The currency is CHF or EUR.',
                ],
            ],
            [
                'jurisdiction' => 'CH', 'source' => 'Swissdec', 'title' => 'Swissdec ELM 5.0', 'ref' => 'ELM 5.0', 'badge' => 'Swissdec ELM 5.0',
                'text' => [
                    'it' => "Lo standard Swissdec ELM (procedura standardizzata di notifica dei salari), nella versione 5.0, permette di trasmettere una sola volta i dati salariali da un software certificato alle casse di compensazione AVS, agli assicuratori infortuni, alle casse pensioni, alle autorità fiscali per l'imposta alla fonte e all'Ufficio federale di statistica.",
                    'de' => 'Der Swissdec-Standard ELM (Standardisiertes Lohnmeldeverfahren) in der Version 5.0 erlaubt es, Lohndaten einmal aus einer zertifizierten Software an AHV-Ausgleichskassen, Unfallversicherer, Pensionskassen, Steuerbehörden für die Quellensteuer und das Bundesamt für Statistik zu übermitteln.',
                    'fr' => "La norme Swissdec ELM (procédure de transmission standardisée des salaires), en version 5.0, permet de transmettre une seule fois les données salariales depuis un logiciel certifié aux caisses de compensation AVS, aux assureurs-accidents, aux caisses de pension, aux autorités fiscales pour l'impôt à la source et à l'Office fédéral de la statistique.",
                    'en' => 'The Swissdec ELM standard (standardised wage reporting procedure), in version 5.0, lets certified software transmit payroll data once to the AHV/AVS compensation funds, accident insurers, pension funds, the tax authorities for withholding tax and the Federal Statistical Office.',
                ],
            ],
            [
                'jurisdiction' => 'IT', 'source' => 'DPR 633/72', 'title' => 'DPR 633/72 – VAT rates', 'ref' => 'Art. 16', 'badge' => 'DPR 633/72 Art. 16',
                'text' => [
                    'it' => "Ai sensi dell'art. 16 del DPR 633/72 l'aliquota IVA ordinaria è il 22%; le aliquote ridotte sono il 10%, il 5% e il 4% per i beni e le prestazioni indicati nelle tabelle e nella parte III della tabella A allegata.",
                    'de' => 'Nach Art. 16 des DPR 633/72 beträgt der ordentliche italienische Mehrwertsteuersatz 22%; ermässigte Sätze von 10%, 5% und 4% gelten für die in den Tabellen und in Teil III der Tabelle A aufgeführten Güter und Leistungen.',
                    'fr' => "Selon l'art. 16 du DPR 633/72, le taux normal de TVA italien est de 22%; des taux réduits de 10%, 5% et 4% s'appliquent aux biens et prestations énumérés dans les tableaux et dans la partie III du tableau A.",
                    'en' => 'Under Art. 16 of DPR 633/72 the ordinary Italian VAT rate is 22%; reduced rates of 10%, 5% and 4% apply to the goods and services listed in the tables and in part III of table A.',
                ],
            ],
            [
                'jurisdiction' => 'IT', 'source' => 'DPR 633/72', 'title' => 'DPR 633/72 – Invoice issuance', 'ref' => 'Art. 21', 'badge' => 'DPR 633/72 Art. 21',
                'text' => [
                    'it' => "L'art. 21 del DPR 633/72 stabilisce il contenuto della fattura e il momento di emissione: la fattura immediata va emessa entro 12 giorni dall'effettuazione dell'operazione tramite il Sistema di Interscambio; la fattura differita, per le cessioni documentate da DDT, può essere emessa entro il giorno 15 del mese successivo.",
                    'de' => 'Art. 21 des DPR 633/72 regelt den Inhalt der Rechnung und den Zeitpunkt der Ausstellung: die Sofortrechnung ist innerhalb von 12 Tagen nach der Leistung über das Sistema di Interscambio auszustellen; die Sammelrechnung für mit Lieferschein dokumentierte Lieferungen kann bis zum 15. des Folgemonats ausgestellt werden.',
                    'fr' => "L'art. 21 du DPR 633/72 fixe le contenu de la facture et le moment de son émission: la facture immédiate doit être émise dans les 12 jours suivant l'opération via le Sistema di Interscambio; la facture différée, pour les livraisons documentées par un bon de livraison, peut être émise jusqu'au 15 du mois suivant.",
                    'en' => 'Art. 21 of DPR 633/72 sets the content of the invoice and when it is issued: an immediate invoice must be issued within 12 days of the transaction through the Sistema di Interscambio; a deferred invoice, for deliveries documented by a delivery note, may be issued by the 15th of the following month.',
                ],
            ],
            [
                'jurisdiction' => 'IT', 'source' => 'DPR 633/72', 'title' => 'DPR 633/72 – Reverse charge', 'ref' => 'Art. 17', 'badge' => 'DPR 633/72 Art. 17',
                'text' => [
                    'it' => "Con l'inversione contabile (reverse charge) dell'art. 17 del DPR 633/72 l'IVA è dovuta dal cessionario o committente e non dal cedente: la fattura non espone l'imposta e riporta la natura N6.x; il cessionario la integra o emette un'autofattura (TD16–TD19) e la registra sia tra gli acquisti sia tra le vendite.",
                    'de' => 'Beim Reverse-Charge-Verfahren nach Art. 17 des DPR 633/72 schuldet der Leistungsempfänger die Mehrwertsteuer und nicht der Lieferant: die Rechnung weist keine Steuer aus und trägt die Natur N6.x; der Empfänger ergänzt sie oder stellt eine Eigenrechnung aus (TD16–TD19) und bucht sie sowohl im Einkaufs- als auch im Verkaufsjournal.',
                    'fr' => "Avec l'autoliquidation (reverse charge) de l'art. 17 du DPR 633/72, la TVA est due par l'acquéreur et non par le fournisseur: la facture n'indique pas la taxe et porte la nature N6.x; l'acquéreur l'intègre ou émet une autofacture (TD16–TD19) et l'enregistre à la fois dans les achats et dans les ventes.",
                    'en' => 'Under the reverse charge of Art. 17 of DPR 633/72 the VAT is due by the customer, not the supplier: the invoice shows no tax and carries nature N6.x; the customer integrates it or issues a self-invoice (TD16–TD19) and records it in both the purchase and the sales registers.',
                ],
            ],
            [
                'jurisdiction' => 'IT', 'source' => 'DPR 642/72', 'title' => 'DPR 642/72 – Stamp duty on invoices', 'ref' => 'Art. 13', 'badge' => 'DPR 642/72 Art. 13',
                'text' => [
                    'it' => "Le fatture senza IVA (esenti, escluse o non imponibili) di importo superiore a 77,47 euro sono soggette all'imposta di bollo di 2 euro, che va indicata in fattura (bollo virtuale) e versata con le scadenze trimestrali.",
                    'de' => 'Rechnungen ohne Mehrwertsteuer (befreit, ausgeschlossen oder nicht steuerbar) über 77,47 Euro unterliegen einer Stempelsteuer von 2 Euro, die auf der Rechnung auszuweisen (virtuelle Stempelmarke) und quartalsweise abzuführen ist.',
                    'fr' => "Les factures sans TVA (exonérées, exclues ou non imposables) d'un montant supérieur à 77,47 euros sont soumises à un droit de timbre de 2 euros, à indiquer sur la facture (timbre virtuel) et à verser par échéances trimestrielles.",
                    'en' => 'Invoices without VAT (exempt, excluded or non-taxable) above 77.47 euro are subject to a stamp duty of 2 euro, which must be stated on the invoice (virtual stamp) and paid in quarterly instalments.',
                ],
            ],
            [
                'jurisdiction' => 'IT', 'source' => 'TUIR', 'title' => 'TUIR – Corporate income tax (IRES)', 'ref' => 'Art. 77', 'badge' => 'TUIR Art. 77',
                'text' => [
                    'it' => "Ai sensi dell'art. 77 del TUIR (DPR 917/86) l'IRES, l'imposta sul reddito delle società, si applica con l'aliquota del 24% al reddito complessivo netto; è distinta dall'IRAP, che ha una base imponibile e aliquote proprie.",
                    'de' => 'Nach Art. 77 des TUIR (DPR 917/86) wird die IRES, die italienische Körperschaftsteuer, mit einem Satz von 24% auf das gesamte Nettoeinkommen erhoben; sie ist von der IRAP getrennt, die eine eigene Bemessungsgrundlage und eigene Sätze hat.',
                    'fr' => "Selon l'art. 77 du TUIR (DPR 917/86), l'IRES, l'impôt italien sur le revenu des sociétés, s'applique au taux de 24% au revenu net global; elle est distincte de l'IRAP, qui a sa propre base et ses propres taux.",
                    'en' => 'Under Art. 77 of the TUIR (DPR 917/86) IRES, the Italian corporate income tax, applies at a rate of 24% to total net income; it is separate from IRAP, which has its own base and rates.',
                ],
            ],
            [
                'jurisdiction' => 'IT', 'source' => 'OIC', 'title' => 'OIC – Italian accounting standards', 'ref' => 'OIC 10, 12, 13, 15, 16, 24', 'badge' => 'OIC',
                'text' => [
                    'it' => "I principi contabili OIC, emanati dall'Organismo Italiano di Contabilità, interpretano gli articoli 2423 e seguenti del Codice civile. Tra i principali: OIC 10 rendiconto finanziario, OIC 12 schemi di bilancio, OIC 13 rimanenze, OIC 15 crediti, OIC 16 immobilizzazioni materiali, OIC 24 immobilizzazioni immateriali.",
                    'de' => 'Die OIC-Rechnungslegungsstandards des Organismo Italiano di Contabilità legen die Artikel 2423 ff. des Zivilgesetzbuches aus. Wichtige Standards: OIC 10 Kapitalflussrechnung, OIC 12 Gliederungsschemata, OIC 13 Vorräte, OIC 15 Forderungen, OIC 16 Sachanlagen, OIC 24 immaterielle Anlagen.',
                    'fr' => "Les normes comptables OIC, publiées par l'Organismo Italiano di Contabilità, interprètent les articles 2423 et suivants du Code civil. Parmi les principales: OIC 10 tableau des flux de trésorerie, OIC 12 schémas des comptes annuels, OIC 13 stocks, OIC 15 créances, OIC 16 immobilisations corporelles, OIC 24 immobilisations incorporelles.",
                    'en' => 'The OIC accounting standards, issued by the Organismo Italiano di Contabilità, interpret Articles 2423 ff. of the Civil Code. Main ones: OIC 10 cash flow statement, OIC 12 financial statement formats, OIC 13 inventories, OIC 15 receivables, OIC 16 tangible fixed assets, OIC 24 intangible fixed assets.',
                ],
            ],
            [
                'jurisdiction' => 'IT', 'source' => 'SDI', 'title' => 'SDI – FatturaPA electronic invoice', 'ref' => 'FPR12 / FPA12', 'badge' => 'SDI FatturaPA',
                'text' => [
                    'it' => 'La fattura elettronica è un file XML FatturaPA trasmesso tramite il Sistema di Interscambio (SdI): formato FPR12 per i privati e FPA12 per la pubblica amministrazione. Il codice destinatario ha 7 caratteri (6 per la PA); se manca si usa 0000000 e la PEC. Le notifiche dello SdI sono RC (consegna), NS (scarto), MC (mancata consegna), NE (esito committente), AT (impossibilità di recapito) e DT (decorrenza termini).',
                    'de' => 'Die elektronische Rechnung ist eine XML-Datei im FatturaPA-Format, die über das Sistema di Interscambio (SdI) übermittelt wird: Format FPR12 für Private und FPA12 für die öffentliche Verwaltung. Der Empfängercode hat 7 Zeichen (6 für die Verwaltung); fehlt er, wird 0000000 und die PEC-Adresse verwendet. SdI-Meldungen: RC (Zustellung), NS (Abweisung), MC (Nichtzustellung), NE (Ergebnis des Auftraggebers), AT (Zustellung unmöglich) und DT (Fristablauf).',
                    'fr' => "La facture électronique est un fichier XML FatturaPA transmis par le Sistema di Interscambio (SdI): format FPR12 pour les privés et FPA12 pour l'administration publique. Le code destinataire compte 7 caractères (6 pour l'administration); à défaut on utilise 0000000 et la PEC. Les notifications du SdI sont RC (livraison), NS (rejet), MC (non-livraison), NE (résultat du client), AT (impossibilité de remise) et DT (expiration du délai).",
                    'en' => 'The electronic invoice is an XML file in the FatturaPA format sent through the Sistema di Interscambio (SdI): format FPR12 for private parties and FPA12 for public administration. The recipient code has 7 characters (6 for public administration); when missing, 0000000 and the PEC address are used. SdI notifications are RC (delivery), NS (rejection), MC (failed delivery), NE (customer outcome), AT (delivery impossible) and DT (terms expired).',
                ],
            ],
            [
                'jurisdiction' => 'IT', 'source' => 'FatturaPA', 'title' => 'FatturaPA – VAT natures N1 to N5', 'ref' => 'N1–N5', 'badge' => 'DPR 633/72 - N1-N5',
                'text' => [
                    'it' => "Natura IVA in assenza di imposta: N1 escluse ex art. 15; N2.1 non soggette ad IVA (artt. da 7 a 7-septies); N2.2 non soggette, altri casi; N3.1 esportazioni; N3.2 cessioni intracomunitarie; N3.3 cessioni verso San Marino; N3.4 operazioni assimilate alle cessioni all'esportazione; N3.5 a seguito di dichiarazioni d'intento; N3.6 altre operazioni non imponibili; N4 esenti; N5 regime del margine, IVA non esposta in fattura.",
                    'de' => 'Mehrwertsteuer-Naturen ohne Steuerausweis: N1 ausgeschlossen nach Art. 15; N2.1 nicht steuerbar (Art. 7 bis 7-septies); N2.2 nicht steuerbar, sonstige Fälle; N3.1 Ausfuhren; N3.2 innergemeinschaftliche Lieferungen; N3.3 Lieferungen nach San Marino; N3.4 den Ausfuhren gleichgestellte Umsätze; N3.5 aufgrund von Absichtserklärungen; N3.6 sonstige nicht steuerpflichtige Umsätze; N4 befreit; N5 Margenregelung, Steuer nicht ausgewiesen.',
                    'fr' => "Natures de TVA sans impôt: N1 exclues selon l'art. 15; N2.1 non soumises à la TVA (art. 7 à 7-septies); N2.2 non soumises, autres cas; N3.1 exportations; N3.2 livraisons intracommunautaires; N3.3 livraisons vers Saint-Marin; N3.4 opérations assimilées aux exportations; N3.5 suite à des déclarations d'intention; N3.6 autres opérations non imposables; N4 exonérées; N5 régime de la marge, TVA non indiquée.",
                    'en' => 'VAT natures without tax: N1 excluded under Art. 15; N2.1 not subject to VAT (Arts. 7 to 7-septies); N2.2 not subject, other cases; N3.1 exports; N3.2 intra-EU supplies; N3.3 supplies to San Marino; N3.4 transactions treated as exports; N3.5 under declarations of intent; N3.6 other non-taxable transactions; N4 exempt; N5 margin scheme, VAT not shown.',
                ],
            ],
            [
                'jurisdiction' => 'IT', 'source' => 'FatturaPA', 'title' => 'FatturaPA – VAT natures N6 (reverse charge)', 'ref' => 'N6.1–N6.9', 'badge' => 'DPR 633/72 - N6.9',
                'text' => [
                    'it' => "Nature IVA dell'inversione contabile: N6.1 cessione di rottami e materiali di recupero; N6.2 oro e argento puro; N6.3 subappalto nel settore edile; N6.4 cessione di fabbricati; N6.5 telefoni cellulari; N6.6 prodotti elettronici; N6.7 prestazioni del comparto edile e settori connessi; N6.8 settore energetico; N6.9 altri casi di inversione contabile.",
                    'de' => 'Mehrwertsteuer-Naturen des Reverse-Charge-Verfahrens: N6.1 Verkauf von Schrott und Altmaterial; N6.2 Gold und reines Silber; N6.3 Subunternehmerleistungen im Baugewerbe; N6.4 Verkauf von Gebäuden; N6.5 Mobiltelefone; N6.6 elektronische Produkte; N6.7 Leistungen im Baugewerbe und verwandten Branchen; N6.8 Energiesektor; N6.9 sonstige Reverse-Charge-Fälle.',
                    'fr' => "Natures de TVA de l'autoliquidation: N6.1 cession de ferrailles et matériaux de récupération; N6.2 or et argent pur; N6.3 sous-traitance dans le bâtiment; N6.4 cession de bâtiments; N6.5 téléphones portables; N6.6 produits électroniques; N6.7 prestations du secteur du bâtiment et secteurs connexes; N6.8 secteur de l'énergie; N6.9 autres cas d'autoliquidation.",
                    'en' => 'VAT natures of the reverse charge: N6.1 sale of scrap and recovered materials; N6.2 gold and pure silver; N6.3 subcontracting in construction; N6.4 sale of buildings; N6.5 mobile phones; N6.6 electronic products; N6.7 services in construction and related sectors; N6.8 energy sector; N6.9 other reverse charge cases.',
                ],
            ],
            [
                'jurisdiction' => 'IT', 'source' => 'FatturaPA', 'title' => 'FatturaPA – VAT nature N7', 'ref' => 'N7', 'badge' => 'DPR 633/72 - N7',
                'text' => [
                    'it' => 'Natura N7: IVA assolta in altro Stato UE, per le vendite a distanza di beni e le prestazioni di telecomunicazione, teleradiodiffusione ed elettroniche verso consumatori finali (regime OSS, artt. 40, c. 3 e 4 e 41, c. 1, lett. b, DL 331/93 e art. 7-sexies, lett. f, g, DPR 633/72).',
                    'de' => 'Natur N7: in einem anderen EU-Staat entrichtete Mehrwertsteuer, für Fernverkäufe von Gütern sowie Telekommunikations-, Rundfunk- und elektronische Dienstleistungen an Endverbraucher (OSS-Regelung, Art. 40 Abs. 3 und 4 und Art. 41 Abs. 1 Bst. b DL 331/93 sowie Art. 7-sexies Bst. f, g DPR 633/72).',
                    'fr' => "Nature N7: TVA acquittée dans un autre État de l'UE, pour les ventes à distance de biens et les services de télécommunication, de radiodiffusion et électroniques à des consommateurs finaux (régime OSS, art. 40 al. 3 et 4 et art. 41 al. 1 let. b DL 331/93 et art. 7-sexies let. f, g DPR 633/72).",
                    'en' => 'Nature N7: VAT paid in another EU Member State, for distance sales of goods and telecommunication, broadcasting and electronic services to final consumers (OSS scheme, Art. 40 paras 3 and 4 and Art. 41 para 1 letter b of DL 331/93 and Art. 7-sexies letters f, g of DPR 633/72).',
                ],
            ],
            [
                'jurisdiction' => 'IT', 'source' => 'FatturaPA', 'title' => 'FatturaPA – Document types TD01 to TD09', 'ref' => 'TD01–TD09', 'badge' => 'SDI TD01-TD09',
                'text' => [
                    'it' => 'Tipi documento: TD01 fattura; TD02 acconto o anticipo su fattura; TD03 acconto o anticipo su parcella; TD04 nota di credito; TD05 nota di debito; TD06 parcella; TD07 fattura semplificata; TD08 nota di credito semplificata; TD09 nota di debito semplificata.',
                    'de' => 'Dokumenttypen: TD01 Rechnung; TD02 Akontozahlung oder Vorschuss auf Rechnung; TD03 Akontozahlung oder Vorschuss auf Honorarnote; TD04 Gutschrift; TD05 Belastungsanzeige; TD06 Honorarnote; TD07 vereinfachte Rechnung; TD08 vereinfachte Gutschrift; TD09 vereinfachte Belastungsanzeige.',
                    'fr' => "Types de documents: TD01 facture; TD02 acompte ou avance sur facture; TD03 acompte ou avance sur note d'honoraires; TD04 note de crédit; TD05 note de débit; TD06 note d'honoraires; TD07 facture simplifiée; TD08 note de crédit simplifiée; TD09 note de débit simplifiée.",
                    'en' => 'Document types: TD01 invoice; TD02 advance or deposit on invoice; TD03 advance or deposit on fee note; TD04 credit note; TD05 debit note; TD06 fee note; TD07 simplified invoice; TD08 simplified credit note; TD09 simplified debit note.',
                ],
            ],
            [
                'jurisdiction' => 'IT', 'source' => 'FatturaPA', 'title' => 'FatturaPA – Document types TD16 to TD28', 'ref' => 'TD16–TD28', 'badge' => 'SDI TD16-TD28',
                'text' => [
                    'it' => "Tipi documento: TD16 integrazione fattura reverse charge interno; TD17 integrazione o autofattura per acquisto di servizi dall'estero; TD18 integrazione per acquisto di beni intracomunitari; TD19 integrazione o autofattura per acquisto di beni ex art. 17, c. 2; TD20 autofattura per regolarizzazione; TD21 autofattura per splafonamento; TD22 estrazione di beni da deposito IVA; TD23 estrazione di beni da deposito IVA con versamento dell'IVA; TD24 fattura differita, art. 21, c. 4, lett. a; TD25 fattura differita, art. 21, c. 4, terzo periodo, lett. b; TD26 cessione di beni ammortizzabili e passaggi interni, art. 36 DPR 633/72; TD27 fattura per autoconsumo o cessioni gratuite senza rivalsa; TD28 acquisti da San Marino con IVA.",
                    'de' => 'Dokumenttypen: TD16 Ergänzung einer Reverse-Charge-Rechnung im Inland; TD17 Ergänzung oder Eigenrechnung für Dienstleistungen aus dem Ausland; TD18 Ergänzung für innergemeinschaftliche Warenkäufe; TD19 Ergänzung oder Eigenrechnung für Warenkäufe nach Art. 17 Abs. 2; TD20 Eigenrechnung zur Regularisierung; TD21 Eigenrechnung bei Überschreitung der Plafonds; TD22 Entnahme von Gütern aus einem Mehrwertsteuerlager; TD23 Entnahme aus dem Mehrwertsteuerlager mit Steuerzahlung; TD24 Sammelrechnung, Art. 21 Abs. 4 Bst. a; TD25 Sammelrechnung, Art. 21 Abs. 4 dritter Satz Bst. b; TD26 Verkauf abschreibbarer Güter und interne Übergänge, Art. 36 DPR 633/72; TD27 Rechnung für Eigenverbrauch oder unentgeltliche Abgaben ohne Überwälzung; TD28 Käufe aus San Marino mit Mehrwertsteuer.',
                    'fr' => "Types de documents: TD16 intégration de facture en autoliquidation interne; TD17 intégration ou autofacture pour services acquis à l'étranger; TD18 intégration pour achats intracommunautaires de biens; TD19 intégration ou autofacture pour achats de biens selon l'art. 17 al. 2; TD20 autofacture de régularisation; TD21 autofacture pour dépassement du plafond; TD22 extraction de biens d'un entrepôt TVA; TD23 extraction d'un entrepôt TVA avec paiement de la TVA; TD24 facture différée, art. 21 al. 4 let. a; TD25 facture différée, art. 21 al. 4 troisième période let. b; TD26 cession de biens amortissables et passages internes, art. 36 DPR 633/72; TD27 facture pour autoconsommation ou cessions gratuites sans répercussion; TD28 achats à Saint-Marin avec TVA.",
                    'en' => 'Document types: TD16 integration of a domestic reverse charge invoice; TD17 integration or self-invoice for services bought from abroad; TD18 integration for intra-EU purchases of goods; TD19 integration or self-invoice for purchases of goods under Art. 17 para 2; TD20 self-invoice for regularisation; TD21 self-invoice for exceeding the ceiling; TD22 extraction of goods from a VAT warehouse; TD23 extraction from a VAT warehouse with VAT payment; TD24 deferred invoice, Art. 21 para 4 letter a; TD25 deferred invoice, Art. 21 para 4 third sentence letter b; TD26 sale of depreciable assets and internal transfers, Art. 36 DPR 633/72; TD27 invoice for self-consumption or free supplies without recharge; TD28 purchases from San Marino with VAT.',
                ],
            ],
            [
                'jurisdiction' => 'GLOBAL', 'source' => 'Academy', 'title' => 'Mod 112 – Academy / LMS', 'ref' => 'Mod 112', 'badge' => 'Mod 112 Academy',
                'text' => [
                    'it' => "Mod 112 Academy: percorsi formativi (contabilità svizzera per PMI, FatturaPA e IVA italiana, magazzino e MRP, HR e paghe CH/IT, architettura ERP) con missioni pratiche. Una missione si avvia e si completa solo in un tenant Demo/Sandbox (Mod 105): è completata dagli eventi di dominio dell'ERP, mai dai dati di produzione. Ogni missione assegna XP e badge; completando un percorso si riceve un certificato PDF con codice QR, verificabile pubblicamente. Aiuto contestuale e tooltip dei campi sono disponibili per le schermate principali in it-CH, it-IT, de-CH, fr-CH ed en.",
                    'de' => 'Mod 112 Academy: Lernpfade (Schweizer Buchhaltung für KMU, FatturaPA und italienische MwSt, Lager und MRP, HR und Lohn CH/IT, ERP-Architektur) mit praktischen Missionen. Eine Mission wird nur in einem Demo-/Sandbox-Mandanten (Mod 105) gestartet und abgeschlossen: sie wird durch Domänenereignisse des ERP erfüllt, nie durch Produktivdaten. Jede Mission vergibt XP und Badges; mit dem Abschluss eines Lernpfads erhält man ein PDF-Zertifikat mit QR-Code, das öffentlich verifizierbar ist. Kontexthilfe und Feld-Tooltips gibt es für die wichtigsten Bildschirme in it-CH, it-IT, de-CH, fr-CH und en.',
                    'fr' => "Mod 112 Academy: parcours de formation (comptabilité suisse pour PME, FatturaPA et TVA italienne, entrepôt et MRP, RH et paie CH/IT, architecture ERP) avec des missions pratiques. Une mission ne se démarre et ne s'achève que dans un tenant Démo/Sandbox (Mod 105): elle est validée par les événements de domaine de l'ERP, jamais par des données de production. Chaque mission donne des XP et des badges; l'achèvement d'un parcours délivre un certificat PDF avec code QR, vérifiable publiquement. L'aide contextuelle et les infobulles de champs existent pour les écrans principaux en it-CH, it-IT, de-CH, fr-CH et en.",
                    'en' => 'Mod 112 Academy: learning tracks (Swiss accounting for SMEs, Italian FatturaPA and VAT, warehouse and MRP, HR and payroll CH/IT, ERP developer architecture) with hands-on missions. A mission is started and completed only in a Demo/Sandbox tenant (Mod 105): it is fulfilled by ERP domain events, never by production data. Each mission awards XP and badges; finishing a track issues a PDF certificate with a QR code that anyone can verify publicly. Contextual help and field tooltips cover the main screens in it-CH, it-IT, de-CH, fr-CH and en.',
                ],
            ],
        ];
    }
}
