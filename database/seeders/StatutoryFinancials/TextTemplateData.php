<?php

declare(strict_types=1);

namespace Database\Seeders\StatutoryFinancials;

/**
 * Wording of the statutory financial statement dossier (Mod 167): narrative sections of the Nota Integrativa (Art. 2427 c.c.) and
 * of the Anhang (CO Art. 959c, Swiss GAAP FER), the management report, the board and shareholders minutes, the messages of the
 * validation report, the headings of the PDF/A documents and the descriptions of the standards. Everything is seeded in
 * it / en / de / fr; the frontend only renders what the API returns. Placeholders are written {{name}}.
 *
 * Row: [key, kind, family, placeholders, [it, en, de, fr]] where every language is [title, body].
 * The wording is a starting point to be reviewed by the accountant and the legal adviser of the company before the deposit.
 */
final class TextTemplateData
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $rows = [];
        $order = 0;

        foreach (array_merge(self::italianNotes(), self::swissNotes(), self::managementReport(), self::minutes(), self::validation(), self::reportLabels(), self::standards()) as [$key, $kind, $family, $placeholders, $languages]) {
            $content = [];

            foreach (['it', 'en', 'de', 'fr'] as $index => $locale) {
                $content[$locale] = ['title' => $languages[$index][0], 'body' => $languages[$index][1]];
            }

            $rows[] = ['template_key' => $key, 'kind' => $kind, 'family' => $family, 'sort_order' => ++$order * 10, 'content' => $content, 'placeholders' => $placeholders];
        }

        return $rows;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: list<string>, 4: list<array{0: string, 1: string}>}>
     */
    private static function italianNotes(): array
    {
        $p = ['company_name', 'fiscal_year', 'net_result', 'currency', 'standard'];

        return [
            ['it.notes.criteri_valutazione', 'narrative_section', 'IT', $p, [
                ['Criteri di valutazione e principi contabili', 'Il bilancio di {{company_name}} per l\'esercizio {{fiscal_year}} è redatto secondo gli articoli 2423 e seguenti del codice civile ({{standard}}) e i principi contabili OIC. I criteri di valutazione sono quelli della prudenza e della continuità aziendale, nel rispetto del principio di competenza. Importi espressi in {{currency}}.'],
                ['Valuation criteria and accounting principles', 'The financial statements of {{company_name}} for the year {{fiscal_year}} are prepared under articles 2423 et seq. of the Italian Civil Code ({{standard}}) and the OIC accounting standards. Valuation follows prudence and the going-concern assumption, on an accrual basis. Amounts are in {{currency}}.'],
                ['Bewertungskriterien und Rechnungslegungsgrundsätze', 'Der Abschluss der {{company_name}} für das Geschäftsjahr {{fiscal_year}} wird nach Art. 2423 ff. des italienischen Zivilgesetzbuchs ({{standard}}) und den OIC-Grundsätzen erstellt. Die Bewertung folgt dem Vorsichts- und dem Fortführungsprinzip nach dem Periodenabgrenzungsprinzip. Beträge in {{currency}}.'],
                ['Critères d\'évaluation et principes comptables', 'Les comptes de {{company_name}} pour l\'exercice {{fiscal_year}} sont établis selon les articles 2423 et suivants du code civil italien ({{standard}}) et les principes comptables OIC. L\'évaluation suit la prudence et la continuité d\'exploitation, selon le principe de la comptabilité d\'engagement. Montants en {{currency}}.'],
            ]],
            ['it.notes.immobilizzazioni', 'narrative_section', 'IT', $p, [
                ['Movimentazione delle immobilizzazioni', 'Le immobilizzazioni sono iscritte al costo di acquisto o di produzione e ammortizzate in base alla vita utile stimata. Il prospetto riporta, per ogni categoria, costo storico, rivalutazioni, incrementi, decrementi, fondo ammortamento e valore netto contabile (art. 2427, n. 2 c.c.).'],
                ['Movements in fixed assets', 'Fixed assets are stated at purchase or production cost and depreciated over their estimated useful life. The table shows, per category, historical cost, revaluations, additions, disposals, accumulated depreciation and net book value (art. 2427, no. 2 Civil Code).'],
                ['Entwicklung des Anlagevermögens', 'Das Anlagevermögen wird zu Anschaffungs- oder Herstellungskosten bilanziert und über die geschätzte Nutzungsdauer abgeschrieben. Die Tabelle zeigt je Kategorie historische Kosten, Aufwertungen, Zugänge, Abgänge, kumulierte Abschreibungen und Nettobuchwert (Art. 2427 Nr. 2 ZGB-IT).'],
                ['Mouvements des immobilisations', 'Les immobilisations sont comptabilisées au coût d\'acquisition ou de production et amorties sur leur durée d\'utilité estimée. Le tableau présente, par catégorie, coût historique, réévaluations, acquisitions, cessions, amortissements cumulés et valeur nette comptable (art. 2427, n° 2 c.c.).'],
            ]],
            ['it.notes.crediti_debiti', 'narrative_section', 'IT', $p, [
                ['Crediti e debiti per scadenza', 'Crediti e debiti sono ripartiti per scadenza: entro l\'esercizio successivo, oltre l\'esercizio ed entro cinque anni, oltre cinque anni. Sono evidenziati i debiti assistiti da garanzie reali su beni sociali (art. 2427, nn. 6 e 6-bis c.c.).'],
                ['Receivables and payables by maturity', 'Receivables and payables are split by maturity: within the next year, beyond one year and within five years, beyond five years. Payables secured by collateral on company assets are disclosed (art. 2427, nos. 6 and 6-bis Civil Code).'],
                ['Forderungen und Verbindlichkeiten nach Fälligkeit', 'Forderungen und Verbindlichkeiten werden nach Fälligkeit gegliedert: innerhalb eines Jahres, über ein Jahr bis fünf Jahre, über fünf Jahre. Durch Sicherheiten auf Gesellschaftsvermögen gedeckte Verbindlichkeiten werden ausgewiesen (Art. 2427 Nr. 6 und 6-bis ZGB-IT).'],
                ['Créances et dettes par échéance', 'Les créances et dettes sont ventilées par échéance: à moins d\'un an, de un à cinq ans, à plus de cinq ans. Les dettes garanties par des sûretés réelles sur des biens de la société sont indiquées (art. 2427, n° 6 et 6-bis c.c.).'],
            ]],
            ['it.notes.patrimonio_netto', 'narrative_section', 'IT', $p, [
                ['Variazioni del patrimonio netto', 'Il prospetto espone le variazioni intervenute nelle voci del patrimonio netto: destinazione del risultato dell\'esercizio precedente, aumenti di capitale, altre variazioni e risultato dell\'esercizio {{fiscal_year}} pari a {{net_result}} {{currency}} (art. 2427, n. 4 c.c.).'],
                ['Changes in equity', 'The table shows the changes in each equity item: allocation of the prior-year result, capital increases, other movements and the result for {{fiscal_year}} of {{net_result}} {{currency}} (art. 2427, no. 4 Civil Code).'],
                ['Veränderungen des Eigenkapitals', 'Die Tabelle zeigt die Veränderungen der Eigenkapitalposten: Verwendung des Vorjahresergebnisses, Kapitalerhöhungen, übrige Veränderungen und das Ergebnis {{fiscal_year}} von {{net_result}} {{currency}} (Art. 2427 Nr. 4 ZGB-IT).'],
                ['Variations des capitaux propres', 'Le tableau présente les variations de chaque poste des capitaux propres: affectation du résultat précédent, augmentations de capital, autres mouvements et résultat {{fiscal_year}} de {{net_result}} {{currency}} (art. 2427, n° 4 c.c.).'],
            ]],
            ['it.notes.compensi', 'narrative_section', 'IT', $p, [
                ['Compensi, anticipazioni e crediti verso amministratori e sindaci', 'Sono indicati i compensi spettanti agli amministratori e ai sindaci, le anticipazioni e i crediti concessi e gli impegni assunti per loro conto per effetto di garanzie prestate (art. 2427, n. 16 c.c.).'],
                ['Fees, advances and loans to directors and statutory auditors', 'The fees due to directors and statutory auditors, advances and loans granted and commitments made on their behalf through guarantees are disclosed (art. 2427, no. 16 Civil Code).'],
                ['Vergütungen, Vorschüsse und Kredite an Verwaltungsräte und Revisoren', 'Ausgewiesen werden die Vergütungen an Verwaltungsräte und Revisoren, gewährte Vorschüsse und Kredite sowie für sie eingegangene Garantieverpflichtungen (Art. 2427 Nr. 16 ZGB-IT).'],
                ['Rémunérations, avances et crédits aux administrateurs et commissaires', 'Les rémunérations des administrateurs et commissaires, les avances et crédits accordés et les engagements pris pour leur compte par des garanties sont indiqués (art. 2427, n° 16 c.c.).'],
            ]],
            ['it.notes.dipendenti', 'narrative_section', 'IT', $p, [
                ['Numero medio dei dipendenti', 'Il numero medio dei dipendenti ripartito per categoria è esposto nel prospetto (art. 2427, n. 15 c.c.).'],
                ['Average number of employees', 'The average number of employees by category is shown in the table (art. 2427, no. 15 Civil Code).'],
                ['Durchschnittliche Mitarbeiterzahl', 'Die durchschnittliche Mitarbeiterzahl nach Kategorien ist in der Tabelle ausgewiesen (Art. 2427 Nr. 15 ZGB-IT).'],
                ['Effectif moyen', 'L\'effectif moyen par catégorie figure dans le tableau (art. 2427, n° 15 c.c.).'],
            ]],
            ['it.notes.leasing', 'narrative_section', 'IT', $p, [
                ['Operazioni di locazione finanziaria', 'Per i contratti di leasing sono indicati l\'impegno residuo, il valore di riscatto e gli oneri finanziari di competenza, secondo il metodo patrimoniale previsto dagli OIC (art. 2427, n. 22 c.c.).'],
                ['Finance lease transactions', 'For lease contracts the remaining commitment, the redemption value and the finance charges of the year are disclosed, under the OIC method (art. 2427, no. 22 Civil Code).'],
                ['Finanzierungsleasing', 'Für Leasingverträge werden die Restverpflichtung, der Rückkaufswert und der Finanzaufwand des Jahres nach der OIC-Methode ausgewiesen (Art. 2427 Nr. 22 ZGB-IT).'],
                ['Opérations de crédit-bail', 'Pour les contrats de crédit-bail sont indiqués l\'engagement restant, la valeur de rachat et les charges financières de l\'exercice, selon la méthode OIC (art. 2427, n° 22 c.c.).'],
            ]],
            ['it.notes.impegni', 'narrative_section', 'IT', $p, [
                ['Impegni, garanzie e passività potenziali', 'Gli impegni, le garanzie e le passività potenziali non risultanti dallo stato patrimoniale sono indicati con la loro natura e la ripartizione per tipologia (art. 2427, n. 9 c.c.).'],
                ['Commitments, guarantees and contingent liabilities', 'Commitments, guarantees and contingent liabilities not shown on the balance sheet are disclosed by nature and type (art. 2427, no. 9 Civil Code).'],
                ['Verpflichtungen, Garantien und Eventualverbindlichkeiten', 'Nicht in der Bilanz erscheinende Verpflichtungen, Garantien und Eventualverbindlichkeiten werden nach Art und Typ ausgewiesen (Art. 2427 Nr. 9 ZGB-IT).'],
                ['Engagements, garanties et passifs éventuels', 'Les engagements, garanties et passifs éventuels ne figurant pas au bilan sont indiqués par nature et par type (art. 2427, n° 9 c.c.).'],
            ]],
            ['it.notes.parti_correlate', 'narrative_section', 'IT', $p, [
                ['Operazioni con parti correlate', 'Le operazioni con parti correlate rilevanti e non concluse a normali condizioni di mercato sono indicate con l\'importo, la natura del rapporto e ogni altra informazione necessaria (art. 2427, n. 22-bis c.c.).'],
                ['Related party transactions', 'Material related-party transactions not concluded at arm\'s length are disclosed with amount, nature of the relationship and any other necessary information (art. 2427, no. 22-bis Civil Code).'],
                ['Geschäfte mit nahestehenden Personen', 'Wesentliche, nicht zu marktüblichen Bedingungen abgeschlossene Geschäfte mit nahestehenden Personen werden mit Betrag, Art der Beziehung und weiteren Angaben ausgewiesen (Art. 2427 Nr. 22-bis ZGB-IT).'],
                ['Transactions avec les parties liées', 'Les transactions significatives avec des parties liées non conclues aux conditions normales de marché sont indiquées avec le montant, la nature de la relation et toute autre information nécessaire (art. 2427, n° 22-bis c.c.).'],
            ]],
            ['it.notes.imposte', 'narrative_section', 'IT', $p, [
                ['Imposte sul reddito, correnti e differite', 'Le imposte sul reddito dell\'esercizio comprendono l\'IRES e l\'IRAP correnti, le imposte differite e anticipate. Il prospetto riconcilia l\'onere fiscale teorico con quello effettivo (art. 2427, n. 14 c.c.).'],
                ['Income taxes, current and deferred', 'Income taxes for the year comprise current IRES and IRAP, deferred and prepaid taxes. The table reconciles the theoretical and the effective tax charge (art. 2427, no. 14 Civil Code).'],
                ['Ertragssteuern, laufende und latente', 'Die Ertragssteuern des Jahres umfassen laufende IRES und IRAP sowie latente Steuern. Die Tabelle leitet die theoretische auf die effektive Steuerbelastung über (Art. 2427 Nr. 14 ZGB-IT).'],
                ['Impôts sur le résultat, courants et différés', 'Les impôts sur le résultat de l\'exercice comprennent l\'IRES et l\'IRAP courants, les impôts différés et anticipés. Le tableau réconcilie la charge fiscale théorique et effective (art. 2427, n° 14 c.c.).'],
            ]],
            ['it.notes.fatti_successivi', 'narrative_section', 'IT', $p, [
                ['Fatti di rilievo avvenuti dopo la chiusura', 'Dopo la chiusura dell\'esercizio {{fiscal_year}} non si sono verificati fatti di rilievo tali da modificare i valori di bilancio, salvo quanto di seguito indicato (art. 2427, n. 22-quater c.c.).'],
                ['Significant events after year-end', 'After the close of {{fiscal_year}} no significant events occurred that would change the figures of the financial statements, except as stated below (art. 2427, no. 22-quater Civil Code).'],
                ['Wesentliche Ereignisse nach dem Bilanzstichtag', 'Nach dem Abschluss {{fiscal_year}} sind keine wesentlichen Ereignisse eingetreten, die die Bilanzwerte verändern würden, ausser den nachstehend genannten (Art. 2427 Nr. 22-quater ZGB-IT).'],
                ['Événements significatifs postérieurs à la clôture', 'Après la clôture de {{fiscal_year}}, aucun événement significatif n\'est survenu qui modifierait les montants des comptes, sauf ce qui est indiqué ci-après (art. 2427, n° 22-quater c.c.).'],
            ]],
            ['it.notes.destinazione_risultato', 'narrative_section', 'IT', $p, [
                ['Proposta di destinazione del risultato', 'Si propone all\'assemblea di destinare il risultato dell\'esercizio {{fiscal_year}}, pari a {{net_result}} {{currency}}, come indicato nel prospetto di destinazione (art. 2427, n. 22-septies c.c.).'],
                ['Proposal for the allocation of the result', 'The shareholders\' meeting is asked to allocate the result for {{fiscal_year}} of {{net_result}} {{currency}} as shown in the allocation table (art. 2427, no. 22-septies Civil Code).'],
                ['Antrag zur Ergebnisverwendung', 'Der Versammlung wird beantragt, das Ergebnis {{fiscal_year}} von {{net_result}} {{currency}} gemäss der Verwendungstabelle zu verwenden (Art. 2427 Nr. 22-septies ZGB-IT).'],
                ['Proposition d\'affectation du résultat', 'Il est proposé à l\'assemblée d\'affecter le résultat {{fiscal_year}} de {{net_result}} {{currency}} comme indiqué au tableau d\'affectation (art. 2427, n° 22-septies c.c.).'],
            ]],
            ['it.notes.continuita', 'narrative_section', 'IT', $p, [
                ['Continuità aziendale e adeguati assetti', 'Gli amministratori hanno valutato la capacità dell\'impresa di continuare a operare come entità in funzionamento per almeno dodici mesi e l\'adeguatezza degli assetti organizzativi, amministrativi e contabili (art. 2086 c.c.; art. 3 D.Lgs. 14/2019), anche con gli indicatori di allerta e il DSCR a sei mesi.'],
                ['Going concern and adequate structures', 'The directors assessed the ability of the company to continue as a going concern for at least twelve months and the adequacy of its organisational, administrative and accounting structures (art. 2086 Civil Code; art. 3 Legislative Decree 14/2019), including the early-warning indicators and the six-month DSCR.'],
                ['Unternehmensfortführung und angemessene Strukturen', 'Die Verwaltungsräte haben die Fähigkeit zur Unternehmensfortführung für mindestens zwölf Monate und die Angemessenheit der organisatorischen, administrativen und buchhalterischen Strukturen beurteilt (Art. 2086 ZGB-IT; Art. 3 GvD 14/2019), auch anhand der Frühwarnindikatoren und der DSCR über sechs Monate.'],
                ['Continuité d\'exploitation et structures adéquates', 'Les administrateurs ont évalué la capacité de l\'entreprise à poursuivre son exploitation pendant au moins douze mois et l\'adéquation de ses structures organisationnelles, administratives et comptables (art. 2086 c.c.; art. 3 D.Lgs. 14/2019), notamment avec les indicateurs d\'alerte et le DSCR à six mois.'],
            ]],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: list<string>, 4: list<array{0: string, 1: string}>}>
     */
    private static function swissNotes(): array
    {
        $p = ['company_name', 'fiscal_year', 'net_result', 'currency', 'standard'];

        return [
            ['ch.notes.principles', 'narrative_section', 'CH', $p, [
                ['Principi di allestimento del conto annuale', 'Il conto annuale di {{company_name}} per l\'esercizio {{fiscal_year}} è allestito secondo le disposizioni del Codice delle obbligazioni sulla contabilità commerciale e la presentazione dei conti ({{standard}}). I principi applicati, in particolare i criteri di valutazione, sono descritti qui (art. 959c cpv. 1 n. 1 CO). Importi in {{currency}}.'],
                ['Principles of preparing the annual accounts', 'The annual accounts of {{company_name}} for {{fiscal_year}} are prepared under the Swiss Code of Obligations on accounting and financial reporting ({{standard}}). The principles applied, in particular the valuation rules, are described here (art. 959c para. 1 no. 1 CO). Amounts in {{currency}}.'],
                ['Grundsätze der Rechnungslegung', 'Die Jahresrechnung der {{company_name}} für {{fiscal_year}} wird nach den Vorschriften des Obligationenrechts über die kaufmännische Buchführung und Rechnungslegung ({{standard}}) erstellt. Die angewandten Grundsätze, insbesondere die Bewertungsregeln, sind hier beschrieben (Art. 959c Abs. 1 Ziff. 1 OR). Beträge in {{currency}}.'],
                ['Principes d\'établissement des comptes annuels', 'Les comptes annuels de {{company_name}} pour {{fiscal_year}} sont établis selon les dispositions du Code des obligations sur la comptabilité commerciale et la présentation des comptes ({{standard}}). Les principes appliqués, notamment les règles d\'évaluation, sont décrits ici (art. 959c al. 1 ch. 1 CO). Montants en {{currency}}.'],
            ]],
            ['ch.notes.fer_framework', 'narrative_section', 'CH', $p, [
                ['Quadro di riferimento Swiss GAAP FER', 'I conti sono presentati secondo le Swiss GAAP FER (FER 1-31) e danno un quadro fedele della situazione patrimoniale, finanziaria e reddituale (true and fair view). Comprendono bilancio, conto economico, rendiconto finanziario (FER 4), prospetto delle variazioni del capitale proprio (FER 24) e allegato.'],
                ['Swiss GAAP FER framework', 'The accounts are presented under Swiss GAAP FER (FER 1-31) and give a true and fair view of the financial position, financial performance and cash flows. They comprise balance sheet, income statement, cash flow statement (FER 4), statement of changes in equity (FER 24) and notes.'],
                ['Rahmenkonzept Swiss GAAP FER', 'Die Rechnung wird nach Swiss GAAP FER (FER 1-31) dargestellt und vermittelt ein den tatsächlichen Verhältnissen entsprechendes Bild der Vermögens-, Finanz- und Ertragslage (True and Fair View). Sie umfasst Bilanz, Erfolgsrechnung, Geldflussrechnung (FER 4), Eigenkapitalnachweis (FER 24) und Anhang.'],
                ['Cadre de référence Swiss GAAP RPC', 'Les comptes sont présentés selon les Swiss GAAP RPC (RPC 1-31) et donnent une image fidèle de la situation patrimoniale, financière et des résultats. Ils comprennent bilan, compte de résultat, tableau de flux de trésorerie (RPC 4), tableau de variation des fonds propres (RPC 24) et annexe.'],
            ]],
            ['ch.notes.fixed_assets', 'narrative_section', 'CH', $p, [
                ['Immobilizzazioni: costi, ammortamenti e valori netti', 'Le immobilizzazioni sono valutate al costo diminuito degli ammortamenti necessari. La tabella espone costi d\'acquisto, aumenti, diminuzioni, ammortamenti cumulati e valori netti per categoria.'],
                ['Fixed assets: cost, depreciation and net values', 'Fixed assets are valued at cost less necessary depreciation. The table shows purchase cost, additions, disposals, accumulated depreciation and net values per category.'],
                ['Anlagevermögen: Anschaffungswerte, Abschreibungen und Nettowerte', 'Das Anlagevermögen wird höchstens zu Anschaffungs- oder Herstellungskosten abzüglich der notwendigen Abschreibungen bewertet. Die Tabelle zeigt Anschaffungswerte, Zugänge, Abgänge, kumulierte Abschreibungen und Nettowerte je Kategorie.'],
                ['Immobilisations: coûts, amortissements et valeurs nettes', 'Les immobilisations sont évaluées au coût diminué des amortissements nécessaires. Le tableau présente coûts d\'acquisition, entrées, sorties, amortissements cumulés et valeurs nettes par catégorie.'],
            ]],
            ['ch.notes.liabilities', 'narrative_section', 'CH', $p, [
                ['Debiti: scadenze e debiti verso istituti di previdenza', 'Sono indicati i debiti per scadenza (entro un anno, da uno a cinque anni, oltre cinque anni), i debiti verso istituti di previdenza e i debiti onerosi con le relative garanzie (art. 959c cpv. 2 n. 5 CO).'],
                ['Liabilities: maturities and pension fund liabilities', 'Liabilities are shown by maturity (within one year, one to five years, beyond five years), together with liabilities to pension funds and interest-bearing debt with its collateral (art. 959c para. 2 no. 5 CO).'],
                ['Verbindlichkeiten: Fälligkeiten und Vorsorgeverbindlichkeiten', 'Die Verbindlichkeiten werden nach Fälligkeit (innert eines Jahres, ein bis fünf Jahre, über fünf Jahre) ausgewiesen, zusammen mit Verbindlichkeiten gegenüber Vorsorgeeinrichtungen und verzinslichen Schulden samt Sicherheiten (Art. 959c Abs. 2 Ziff. 5 OR).'],
                ['Dettes: échéances et dettes envers les institutions de prévoyance', 'Les dettes sont indiquées par échéance (à moins d\'un an, de un à cinq ans, à plus de cinq ans), avec les dettes envers les institutions de prévoyance et les dettes portant intérêt et leurs garanties (art. 959c al. 2 ch. 5 CO).'],
            ]],
            ['ch.notes.participations', 'narrative_section', 'CH', $p, [
                ['Partecipazioni dirette e indirette', 'Sono indicate la ragione sociale, la sede, la quota di capitale e di voti delle partecipazioni dirette e indirette significative (art. 959c cpv. 2 n. 3 CO).'],
                ['Direct and indirect participations', 'Name, registered office and share of capital and votes of the significant direct and indirect participations are disclosed (art. 959c para. 2 no. 3 CO).'],
                ['Direkte und indirekte Beteiligungen', 'Firma, Sitz sowie Kapital- und Stimmenanteil der wesentlichen direkten und indirekten Beteiligungen werden ausgewiesen (Art. 959c Abs. 2 Ziff. 3 OR).'],
                ['Participations directes et indirectes', 'La raison sociale, le siège ainsi que la part du capital et des voix des participations directes et indirectes significatives sont indiqués (art. 959c al. 2 ch. 3 CO).'],
            ]],
            ['ch.notes.fte', 'narrative_section', 'CH', $p, [
                ['Posti a tempo pieno in media annua', 'Il numero di posti a tempo pieno in media annua è indicato per fascia: fino a 10, fino a 50, fino a 250, oltre 250 (art. 959c cpv. 2 n. 2 CO).'],
                ['Full-time equivalents on annual average', 'The annual average number of full-time positions is stated by band: up to 10, up to 50, up to 250, more than 250 (art. 959c para. 2 no. 2 CO).'],
                ['Vollzeitstellen im Jahresdurchschnitt', 'Die Zahl der Vollzeitstellen im Jahresdurchschnitt wird nach Kategorien angegeben: bis 10, bis 50, bis 250, über 250 (Art. 959c Abs. 2 Ziff. 2 OR).'],
                ['Postes à plein temps en moyenne annuelle', 'Le nombre de postes à plein temps en moyenne annuelle est indiqué par catégorie: jusqu\'à 10, jusqu\'à 50, jusqu\'à 250, plus de 250 (art. 959c al. 2 ch. 2 CO).'],
            ]],
            ['ch.notes.leasing', 'narrative_section', 'CH', $p, [
                ['Debiti da leasing non iscritti a bilancio', 'Sono indicati l\'importo dei debiti da leasing non iscritti a bilancio, con la loro scadenza (art. 959c cpv. 2 n. 6 CO).'],
                ['Off-balance-sheet lease liabilities', 'The amount of lease liabilities not shown on the balance sheet is disclosed, with their maturity (art. 959c para. 2 no. 6 CO).'],
                ['Nicht bilanzierte Leasingverbindlichkeiten', 'Der Betrag der nicht bilanzierten Leasingverbindlichkeiten wird mit Fälligkeit ausgewiesen (Art. 959c Abs. 2 Ziff. 6 OR).'],
                ['Dettes de leasing non portées au bilan', 'Le montant des dettes de leasing non portées au bilan est indiqué avec leur échéance (art. 959c al. 2 ch. 6 CO).'],
            ]],
            ['ch.notes.guarantees', 'narrative_section', 'CH', $p, [
                ['Fideiussioni, garanzie e costituzione di pegni a favore di terzi', 'Sono indicati l\'importo complessivo delle obbligazioni di fideiussione, garanzia e costituzione di pegno a favore di terzi (art. 959c cpv. 2 n. 10 CO) e degli attivi costituiti in pegno o ceduti a garanzia di impegni propri (n. 7).'],
                ['Guarantees, pledges and collateral in favour of third parties', 'The total amount of guarantees, pledges and collateral provided in favour of third parties (art. 959c para. 2 no. 10 CO) and of assets pledged or assigned as security for own liabilities (no. 7) is disclosed.'],
                ['Bürgschaften, Garantien und Pfandbestellungen zugunsten Dritter', 'Der Gesamtbetrag der Bürgschaften, Garantieverpflichtungen und Pfandbestellungen zugunsten Dritter (Art. 959c Abs. 2 Ziff. 10 OR) sowie der verpfändeten oder abgetretenen Aktiven zur Sicherung eigener Verpflichtungen (Ziff. 7) wird ausgewiesen.'],
                ['Cautionnements, garanties et constitutions de gages en faveur de tiers', 'Le montant total des cautionnements, garanties et constitutions de gages en faveur de tiers (art. 959c al. 2 ch. 10 CO) et des actifs mis en gage ou cédés en garantie d\'engagements propres (ch. 7) est indiqué.'],
            ]],
            ['ch.notes.treasury_shares', 'narrative_section', 'CH', $p, [
                ['Quote proprie e operazioni sul capitale', 'Sono indicate le quote di capitale proprie detenute dalla società e dalle sue imprese controllate, con le variazioni dell\'esercizio, e i capitali autorizzati o condizionali (art. 959c cpv. 2 n. 8 CO).'],
                ['Treasury shares and capital transactions', 'Own shares held by the company and its subsidiaries are disclosed, with the movements of the year, together with authorised or conditional capital (art. 959c para. 2 no. 8 CO).'],
                ['Eigene Anteile und Kapitaltransaktionen', 'Die von der Gesellschaft und ihren Tochtergesellschaften gehaltenen eigenen Anteile mit den Veränderungen des Jahres sowie genehmigtes oder bedingtes Kapital werden ausgewiesen (Art. 959c Abs. 2 Ziff. 8 OR).'],
                ['Parts propres et opérations sur le capital', 'Les parts propres détenues par la société et ses filiales, avec les mouvements de l\'exercice, ainsi que le capital autorisé ou conditionnel sont indiqués (art. 959c al. 2 ch. 8 CO).'],
            ]],
            ['ch.notes.hidden_reserves', 'narrative_section', 'CH', $p, [
                ['Scioglimento di riserve latenti', 'L\'importo complessivo delle riserve di sostituzione sciolte e delle riserve latenti ulteriori sciolte, se supera quello delle riserve costituite e determina un miglioramento sostanziale del risultato, è indicato nell\'allegato (art. 959c cpv. 1 n. 3 CO). Nella lettura Swiss GAAP FER le riserve latenti non sono ammesse.'],
                ['Release of hidden reserves', 'The net amount of replacement reserves released and additional hidden reserves released, if it exceeds the reserves created and substantially improves the result, is disclosed in the notes (art. 959c para. 1 no. 3 CO). Under Swiss GAAP FER hidden reserves are not permitted.'],
                ['Auflösung stiller Reserven', 'Der Gesamtbetrag der aufgelösten Wiederbeschaffungsreserven und der darüber hinaus aufgelösten stillen Reserven wird im Anhang ausgewiesen, soweit er den der neu gebildeten übersteigt und das Ergebnis wesentlich günstiger darstellt (Art. 959c Abs. 1 Ziff. 3 OR). Nach Swiss GAAP FER sind stille Reserven nicht zulässig.'],
                ['Dissolution de réserves latentes', 'Le montant net des réserves de remplacement dissoutes et des réserves latentes supplémentaires dissoutes, s\'il dépasse celui des réserves constituées et améliore sensiblement le résultat, est indiqué dans l\'annexe (art. 959c al. 1 ch. 3 CO). Selon les Swiss GAAP RPC, les réserves latentes ne sont pas admises.'],
            ]],
            ['ch.notes.related_parties', 'narrative_section', 'CH', $p, [
                ['Operazioni con parti correlate (FER 15)', 'Le operazioni e i saldi con parti correlate sono indicati secondo FER 15, con la natura del rapporto e le condizioni.'],
                ['Related party transactions (FER 15)', 'Transactions and balances with related parties are disclosed under FER 15, with the nature of the relationship and the conditions.'],
                ['Transaktionen mit nahestehenden Personen (FER 15)', 'Transaktionen und Salden mit nahestehenden Personen werden nach FER 15 mit Art der Beziehung und Konditionen ausgewiesen.'],
                ['Transactions avec les parties liées (RPC 15)', 'Les transactions et soldes avec les parties liées sont indiqués selon RPC 15, avec la nature de la relation et les conditions.'],
            ]],
            ['ch.notes.subsequent_events', 'narrative_section', 'CH', $p, [
                ['Eventi importanti successivi alla data di chiusura', 'Sono indicati gli avvenimenti importanti successivi alla data di chiusura del bilancio {{fiscal_year}} (art. 959c cpv. 2 n. 13 CO).'],
                ['Significant events after the balance sheet date', 'Significant events after the balance sheet date of {{fiscal_year}} are disclosed (art. 959c para. 2 no. 13 CO).'],
                ['Wesentliche Ereignisse nach dem Bilanzstichtag', 'Wesentliche Ereignisse nach dem Bilanzstichtag {{fiscal_year}} werden ausgewiesen (Art. 959c Abs. 2 Ziff. 13 OR).'],
                ['Événements importants postérieurs à la date du bilan', 'Les événements importants survenus après la date du bilan {{fiscal_year}} sont indiqués (art. 959c al. 2 ch. 13 CO).'],
            ]],
            ['ch.notes.profit_allocation', 'narrative_section', 'CH', $p, [
                ['Proposta di impiego dell\'utile', 'Il consiglio di amministrazione propone all\'assemblea generale l\'impiego dell\'utile di bilancio come da prospetto, con l\'attribuzione alla riserva legale da utili ai sensi dell\'art. 671 CO (5% dell\'utile fino al 20% del capitale azionario).'],
                ['Proposal for the appropriation of profit', 'The board of directors proposes to the general meeting the appropriation of the balance-sheet profit as shown in the table, including the allocation to the legal reserve from profit under art. 671 CO (5% of the profit until 20% of the share capital).'],
                ['Antrag über die Verwendung des Bilanzgewinns', 'Der Verwaltungsrat beantragt der Generalversammlung die Verwendung des Bilanzgewinns gemäss Tabelle, einschliesslich der Zuweisung an die gesetzliche Gewinnreserve nach Art. 671 OR (5% des Jahresgewinns bis 20% des Aktienkapitals).'],
                ['Proposition d\'emploi du bénéfice', 'Le conseil d\'administration propose à l\'assemblée générale l\'emploi du bénéfice au bilan selon le tableau, y compris l\'attribution à la réserve légale issue du bénéfice selon l\'art. 671 CO (5% du bénéfice jusqu\'à 20% du capital-actions).'],
            ]],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: list<string>, 4: list<array{0: string, 1: string}>}>
     */
    private static function managementReport(): array
    {
        $p = ['company_name', 'fiscal_year', 'net_result', 'currency', 'revenue', 'ebitda', 'total_assets', 'equity', 'legal_reference'];

        return [
            ['mr.intro', 'management_report', 'ALL', $p, [
                ['Relazione sulla gestione', 'Signori soci, il bilancio dell\'esercizio {{fiscal_year}} di {{company_name}} chiude con un risultato di {{net_result}} {{currency}}. La presente relazione è redatta ai sensi di {{legal_reference}} e illustra la situazione della società e l\'andamento della gestione.'],
                ['Management report', 'Dear shareholders, the financial statements of {{company_name}} for {{fiscal_year}} close with a result of {{net_result}} {{currency}}. This report is prepared under {{legal_reference}} and describes the position of the company and the course of business.'],
                ['Lagebericht', 'Sehr geehrte Gesellschafter, der Abschluss der {{company_name}} für {{fiscal_year}} schliesst mit einem Ergebnis von {{net_result}} {{currency}}. Dieser Bericht wird gemäss {{legal_reference}} erstellt und beschreibt die Lage der Gesellschaft und den Geschäftsverlauf.'],
                ['Rapport de gestion', 'Mesdames, Messieurs les associés, les comptes de {{company_name}} pour {{fiscal_year}} se soldent par un résultat de {{net_result}} {{currency}}. Ce rapport est établi selon {{legal_reference}} et décrit la situation de la société et la marche des affaires.'],
            ]],
            ['mr.performance', 'management_report', 'ALL', $p, [
                ['Andamento economico', 'I ricavi dell\'esercizio ammontano a {{revenue}} {{currency}} e il margine operativo lordo (EBITDA) a {{ebitda}} {{currency}}.'],
                ['Economic performance', 'Revenue for the year amounts to {{revenue}} {{currency}} and operating margin before depreciation (EBITDA) to {{ebitda}} {{currency}}.'],
                ['Wirtschaftliche Entwicklung', 'Der Umsatz des Jahres beträgt {{revenue}} {{currency}} und das Betriebsergebnis vor Abschreibungen (EBITDA) {{ebitda}} {{currency}}.'],
                ['Évolution économique', 'Le chiffre d\'affaires de l\'exercice s\'élève à {{revenue}} {{currency}} et la marge d\'exploitation avant amortissements (EBITDA) à {{ebitda}} {{currency}}.'],
            ]],
            ['mr.financial_position', 'management_report', 'ALL', $p, [
                ['Situazione patrimoniale e finanziaria', 'Il totale dell\'attivo è di {{total_assets}} {{currency}} e il patrimonio netto di {{equity}} {{currency}}. Gli indici patrimoniali e finanziari sono riportati nel prospetto degli indicatori.'],
                ['Financial position', 'Total assets amount to {{total_assets}} {{currency}} and equity to {{equity}} {{currency}}. Financial and balance-sheet ratios are shown in the indicator table.'],
                ['Vermögens- und Finanzlage', 'Die Bilanzsumme beträgt {{total_assets}} {{currency}} und das Eigenkapital {{equity}} {{currency}}. Die Kennzahlen zur Vermögens- und Finanzlage sind in der Kennzahlentabelle ausgewiesen.'],
                ['Situation patrimoniale et financière', 'Le total du bilan s\'élève à {{total_assets}} {{currency}} et les capitaux propres à {{equity}} {{currency}}. Les ratios patrimoniaux et financiers figurent dans le tableau des indicateurs.'],
            ]],
            ['mr.risks', 'management_report', 'ALL', $p, [
                ['Principali rischi e incertezze', 'La società è esposta ai rischi di mercato, di credito, di liquidità e di variazione dei tassi e dei cambi. Gli amministratori monitorano tali rischi con gli indicatori di allerta e il DSCR a sei mesi.'],
                ['Main risks and uncertainties', 'The company is exposed to market, credit, liquidity, interest-rate and currency risks. The directors monitor these risks with early-warning indicators and the six-month DSCR.'],
                ['Wesentliche Risiken und Unsicherheiten', 'Die Gesellschaft ist Markt-, Kredit-, Liquiditäts-, Zins- und Währungsrisiken ausgesetzt. Die Verwaltung überwacht diese Risiken mit Frühwarnindikatoren und der DSCR über sechs Monate.'],
                ['Principaux risques et incertitudes', 'La société est exposée aux risques de marché, de crédit, de liquidité, de taux et de change. Les administrateurs suivent ces risques avec les indicateurs d\'alerte et le DSCR à six mois.'],
            ]],
            ['mr.outlook', 'management_report', 'ALL', $p, [
                ['Evoluzione prevedibile della gestione', 'Per l\'esercizio successivo si prevede il proseguimento dell\'attività nel rispetto degli equilibri economici e finanziari, senza eventi che modifichino la continuità aziendale.'],
                ['Expected development', 'For the following year the business is expected to continue while preserving its economic and financial balance, with no event affecting the going concern.'],
                ['Voraussichtliche Entwicklung', 'Für das Folgejahr wird die Fortführung der Geschäftstätigkeit unter Wahrung des wirtschaftlichen und finanziellen Gleichgewichts erwartet, ohne Ereignisse, die die Unternehmensfortführung beeinträchtigen.'],
                ['Évolution prévisible', 'Pour l\'exercice suivant, la poursuite de l\'activité est attendue dans le respect des équilibres économique et financier, sans événement affectant la continuité d\'exploitation.'],
            ]],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: list<string>, 4: list<array{0: string, 1: string}>}>
     */
    private static function minutes(): array
    {
        $p = ['company_name', 'fiscal_year', 'meeting_date', 'meeting_place', 'chair', 'secretary', 'net_result', 'currency', 'allocation', 'attendees'];

        return [
            ['it.minutes.board', 'minutes', 'IT', $p, [
                ['Verbale del Consiglio di Amministrazione', 'Il giorno {{meeting_date}}, presso {{meeting_place}}, si è riunito il Consiglio di Amministrazione di {{company_name}}. Presiede {{chair}}; verbalizza {{secretary}}. Presenti: {{attendees}}. Il Consiglio approva il progetto di bilancio dell\'esercizio {{fiscal_year}} con un risultato di {{net_result}} {{currency}}, la nota integrativa e la relazione sulla gestione, e delibera di sottoporli all\'assemblea con la seguente proposta di destinazione del risultato: {{allocation}}.'],
                ['Minutes of the Board of Directors', 'On {{meeting_date}}, at {{meeting_place}}, the Board of Directors of {{company_name}} met. {{chair}} chaired and {{secretary}} took the minutes. Present: {{attendees}}. The Board approves the draft financial statements for {{fiscal_year}} with a result of {{net_result}} {{currency}}, the notes and the management report, and resolves to submit them to the shareholders with the following proposal for the allocation of the result: {{allocation}}.'],
                ['Protokoll des Verwaltungsrats', 'Am {{meeting_date}} trat in {{meeting_place}} der Verwaltungsrat der {{company_name}} zusammen. Den Vorsitz führte {{chair}}, das Protokoll {{secretary}}. Anwesend: {{attendees}}. Der Verwaltungsrat genehmigt den Entwurf des Abschlusses {{fiscal_year}} mit einem Ergebnis von {{net_result}} {{currency}}, den Anhang und den Lagebericht und beschliesst, sie der Versammlung mit folgendem Antrag zur Ergebnisverwendung vorzulegen: {{allocation}}.'],
                ['Procès-verbal du conseil d\'administration', 'Le {{meeting_date}}, à {{meeting_place}}, le conseil d\'administration de {{company_name}} s\'est réuni. {{chair}} préside et {{secretary}} tient le procès-verbal. Présents: {{attendees}}. Le conseil approuve le projet de comptes {{fiscal_year}} avec un résultat de {{net_result}} {{currency}}, l\'annexe et le rapport de gestion, et décide de les soumettre à l\'assemblée avec la proposition d\'affectation suivante: {{allocation}}.'],
            ]],
            ['it.minutes.shareholders', 'minutes', 'IT', $p, [
                ['Verbale di Assemblea dei soci', 'Il giorno {{meeting_date}}, presso {{meeting_place}}, si è riunita l\'assemblea dei soci di {{company_name}}. Presiede {{chair}}; verbalizza {{secretary}}. Presenti: {{attendees}}. L\'assemblea, esaminati il bilancio dell\'esercizio {{fiscal_year}}, la nota integrativa e la relazione sulla gestione, approva il bilancio con un risultato di {{net_result}} {{currency}} e delibera la destinazione del risultato come segue: {{allocation}}. Il bilancio sarà depositato presso il Registro delle Imprese in formato XBRL.'],
                ['Minutes of the Shareholders\' Meeting', 'On {{meeting_date}}, at {{meeting_place}}, the shareholders of {{company_name}} met. {{chair}} chaired and {{secretary}} took the minutes. Present: {{attendees}}. Having examined the financial statements for {{fiscal_year}}, the notes and the management report, the meeting approves the financial statements with a result of {{net_result}} {{currency}} and resolves the allocation of the result as follows: {{allocation}}. The financial statements will be filed with the Business Register in XBRL format.'],
                ['Protokoll der Gesellschafterversammlung', 'Am {{meeting_date}} trat in {{meeting_place}} die Gesellschafterversammlung der {{company_name}} zusammen. Den Vorsitz führte {{chair}}, das Protokoll {{secretary}}. Anwesend: {{attendees}}. Nach Prüfung des Abschlusses {{fiscal_year}}, des Anhangs und des Lageberichts genehmigt die Versammlung den Abschluss mit einem Ergebnis von {{net_result}} {{currency}} und beschliesst die Ergebnisverwendung wie folgt: {{allocation}}. Der Abschluss wird im XBRL-Format beim Handelsregister hinterlegt.'],
                ['Procès-verbal de l\'assemblée des associés', 'Le {{meeting_date}}, à {{meeting_place}}, l\'assemblée des associés de {{company_name}} s\'est réunie. {{chair}} préside et {{secretary}} tient le procès-verbal. Présents: {{attendees}}. Après examen des comptes {{fiscal_year}}, de l\'annexe et du rapport de gestion, l\'assemblée approuve les comptes avec un résultat de {{net_result}} {{currency}} et décide l\'affectation du résultat comme suit: {{allocation}}. Les comptes seront déposés au registre des entreprises au format XBRL.'],
            ]],
            ['ch.minutes.board', 'minutes', 'CH', $p, [
                ['Verbale del Consiglio di amministrazione', 'Il giorno {{meeting_date}}, presso {{meeting_place}}, si è riunito il consiglio di amministrazione di {{company_name}}. Presiede {{chair}}; verbalizza {{secretary}}. Presenti: {{attendees}}. Il consiglio approva il conto annuale {{fiscal_year}} (utile/perdita {{net_result}} {{currency}}) con l\'allegato e propone all\'assemblea generale l\'impiego dell\'utile di bilancio: {{allocation}}.'],
                ['Minutes of the Board of Directors', 'On {{meeting_date}}, at {{meeting_place}}, the board of directors of {{company_name}} met. {{chair}} chaired and {{secretary}} took the minutes. Present: {{attendees}}. The board approves the annual accounts {{fiscal_year}} (profit/loss {{net_result}} {{currency}}) with the notes and proposes to the general meeting the appropriation of the balance-sheet profit: {{allocation}}.'],
                ['Protokoll des Verwaltungsrats', 'Am {{meeting_date}} trat in {{meeting_place}} der Verwaltungsrat der {{company_name}} zusammen. Den Vorsitz führte {{chair}}, das Protokoll {{secretary}}. Anwesend: {{attendees}}. Der Verwaltungsrat genehmigt die Jahresrechnung {{fiscal_year}} (Gewinn/Verlust {{net_result}} {{currency}}) mit Anhang und beantragt der Generalversammlung die Verwendung des Bilanzgewinns: {{allocation}}.'],
                ['Procès-verbal du conseil d\'administration', 'Le {{meeting_date}}, à {{meeting_place}}, le conseil d\'administration de {{company_name}} s\'est réuni. {{chair}} préside et {{secretary}} tient le procès-verbal. Présents: {{attendees}}. Le conseil approuve les comptes annuels {{fiscal_year}} (bénéfice/perte {{net_result}} {{currency}}) avec l\'annexe et propose à l\'assemblée générale l\'emploi du bénéfice au bilan: {{allocation}}.'],
            ]],
            ['ch.minutes.shareholders', 'minutes', 'CH', $p, [
                ['Verbale dell\'assemblea generale', 'Il giorno {{meeting_date}}, presso {{meeting_place}}, si è riunita l\'assemblea generale di {{company_name}}. Presiede {{chair}}; verbalizza {{secretary}}. Presenti: {{attendees}}. L\'assemblea, preso atto del rapporto dell\'ufficio di revisione, se previsto, approva il conto annuale {{fiscal_year}} (utile/perdita {{net_result}} {{currency}}), delibera l\'impiego dell\'utile di bilancio ({{allocation}}) e dà scarico al consiglio di amministrazione (art. 698 CO).'],
                ['Minutes of the General Meeting', 'On {{meeting_date}}, at {{meeting_place}}, the general meeting of {{company_name}} was held. {{chair}} chaired and {{secretary}} took the minutes. Present: {{attendees}}. Having taken note of the auditors\' report, where required, the meeting approves the annual accounts {{fiscal_year}} (profit/loss {{net_result}} {{currency}}), resolves the appropriation of the balance-sheet profit ({{allocation}}) and discharges the board of directors (art. 698 CO).'],
                ['Protokoll der Generalversammlung', 'Am {{meeting_date}} fand in {{meeting_place}} die Generalversammlung der {{company_name}} statt. Den Vorsitz führte {{chair}}, das Protokoll {{secretary}}. Anwesend: {{attendees}}. Nach Kenntnisnahme des Revisionsberichts, soweit vorgeschrieben, genehmigt die Versammlung die Jahresrechnung {{fiscal_year}} (Gewinn/Verlust {{net_result}} {{currency}}), beschliesst die Verwendung des Bilanzgewinns ({{allocation}}) und erteilt dem Verwaltungsrat Entlastung (Art. 698 OR).'],
                ['Procès-verbal de l\'assemblée générale', 'Le {{meeting_date}}, à {{meeting_place}}, l\'assemblée générale de {{company_name}} s\'est tenue. {{chair}} préside et {{secretary}} tient le procès-verbal. Présents: {{attendees}}. Après avoir pris acte du rapport de l\'organe de révision, si requis, l\'assemblée approuve les comptes annuels {{fiscal_year}} (bénéfice/perte {{net_result}} {{currency}}), décide l\'emploi du bénéfice au bilan ({{allocation}}) et donne décharge au conseil d\'administration (art. 698 CO).'],
            ]],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: list<string>, 4: list<array{0: string, 1: string}>}>
     */
    private static function validation(): array
    {
        return [
            ['validation.balance_equation', 'validation_message', 'ALL', ['assets', 'liabilities'], [['Quadratura dello stato patrimoniale', 'Il totale attivo ({{assets}}) non coincide con il totale passivo e patrimonio netto ({{liabilities}}).'], ['Balance sheet equation', 'Total assets ({{assets}}) differ from total liabilities and equity ({{liabilities}}).'], ['Bilanzgleichung', 'Die Aktiven ({{assets}}) stimmen nicht mit den Passiven ({{liabilities}}) überein.'], ['Équilibre du bilan', 'Le total de l\'actif ({{assets}}) diffère du total du passif et des capitaux propres ({{liabilities}}).']]],
            ['validation.net_profit_alignment', 'validation_message', 'ALL', ['income_statement', 'balance_sheet'], [['Risultato di conto economico e stato patrimoniale', 'L\'utile del conto economico ({{income_statement}}) differisce da quello dello stato patrimoniale ({{balance_sheet}}).'], ['Net result alignment', 'The income statement result ({{income_statement}}) differs from the balance sheet result ({{balance_sheet}}).'], ['Ergebnisabgleich', 'Das Ergebnis der Erfolgsrechnung ({{income_statement}}) weicht von dem der Bilanz ({{balance_sheet}}) ab.'], ['Alignement du résultat', 'Le résultat du compte de résultat ({{income_statement}}) diffère de celui du bilan ({{balance_sheet}}).']]],
            ['validation.cash_reconciliation', 'validation_message', 'ALL', ['cash_flow', 'balance_sheet'], [['Riconciliazione delle disponibilità liquide', 'Le disponibilità finali del rendiconto finanziario ({{cash_flow}}) differiscono da quelle dello stato patrimoniale ({{balance_sheet}}).'], ['Cash reconciliation', 'Closing cash of the cash flow statement ({{cash_flow}}) differs from the balance sheet ({{balance_sheet}}).'], ['Abstimmung der flüssigen Mittel', 'Die flüssigen Mittel am Ende der Geldflussrechnung ({{cash_flow}}) weichen von der Bilanz ({{balance_sheet}}) ab.'], ['Rapprochement de la trésorerie', 'Les disponibilités finales du tableau des flux ({{cash_flow}}) diffèrent de celles du bilan ({{balance_sheet}}).']]],
            ['validation.notes_fixed_assets', 'validation_message', 'ALL', ['notes', 'balance_sheet'], [['Immobilizzazioni: nota e stato patrimoniale', 'Il valore netto delle immobilizzazioni nella nota ({{notes}}) differisce dallo stato patrimoniale ({{balance_sheet}}).'], ['Fixed assets: notes vs balance sheet', 'Net book value of fixed assets in the notes ({{notes}}) differs from the balance sheet ({{balance_sheet}}).'], ['Anlagevermögen: Anhang und Bilanz', 'Der Nettobuchwert des Anlagevermögens im Anhang ({{notes}}) weicht von der Bilanz ({{balance_sheet}}) ab.'], ['Immobilisations: annexe et bilan', 'La valeur nette comptable des immobilisations dans l\'annexe ({{notes}}) diffère du bilan ({{balance_sheet}}).']]],
            ['validation.notes_equity', 'validation_message', 'ALL', ['notes', 'balance_sheet'], [['Patrimonio netto: nota e stato patrimoniale', 'Il patrimonio netto finale nel prospetto delle variazioni ({{notes}}) differisce dallo stato patrimoniale ({{balance_sheet}}).'], ['Equity: notes vs balance sheet', 'Closing equity in the roll-forward ({{notes}}) differs from the balance sheet ({{balance_sheet}}).'], ['Eigenkapital: Anhang und Bilanz', 'Das Eigenkapital am Ende im Eigenkapitalnachweis ({{notes}}) weicht von der Bilanz ({{balance_sheet}}) ab.'], ['Capitaux propres: annexe et bilan', 'Les capitaux propres finaux du tableau de variation ({{notes}}) diffèrent du bilan ({{balance_sheet}}).']]],
            ['validation.notes_receivables', 'validation_message', 'ALL', ['notes', 'balance_sheet'], [['Crediti e debiti per scadenza', 'Il totale per scadenza nella nota ({{notes}}) differisce dallo stato patrimoniale ({{balance_sheet}}).'], ['Receivables and payables by maturity', 'The maturity total in the notes ({{notes}}) differs from the balance sheet ({{balance_sheet}}).'], ['Forderungen und Verbindlichkeiten nach Fälligkeit', 'Die Summe nach Fälligkeit im Anhang ({{notes}}) weicht von der Bilanz ({{balance_sheet}}) ab.'], ['Créances et dettes par échéance', 'Le total par échéance dans l\'annexe ({{notes}}) diffère du bilan ({{balance_sheet}}).']]],
            ['validation.subtotal_residual', 'validation_message', 'ALL', ['statement', 'residual'], [['Subtotale non quadrato', 'Il subtotale del prospetto {{statement}} presenta un residuo di arrotondamento di {{residual}}.'], ['Subtotal residual', 'A subtotal of the {{statement}} statement shows a rounding residual of {{residual}}.'], ['Zwischentotal mit Restdifferenz', 'Ein Zwischentotal der Rechnung {{statement}} weist eine Rundungsrestdifferenz von {{residual}} auf.'], ['Sous-total non équilibré', 'Un sous-total de l\'état {{statement}} présente un écart d\'arrondi de {{residual}}.']]],
            ['validation.xbrl_not_well_formed', 'validation_message', 'ALL', ['error'], [['XBRL non ben formato', 'L\'istanza XBRL non è un documento XML ben formato: {{error}}.'], ['XBRL not well-formed', 'The XBRL instance is not a well-formed XML document: {{error}}.'], ['XBRL nicht wohlgeformt', 'Die XBRL-Instanz ist kein wohlgeformtes XML-Dokument: {{error}}.'], ['XBRL mal formé', 'L\'instance XBRL n\'est pas un document XML bien formé: {{error}}.']]],
            ['validation.xbrl_missing_schema_ref', 'validation_message', 'ALL', [], [['Riferimento alla tassonomia mancante', 'L\'istanza non contiene il riferimento (schemaRef) al punto di ingresso della tassonomia.'], ['Taxonomy reference missing', 'The instance has no schemaRef to the taxonomy entry point.'], ['Taxonomie-Verweis fehlt', 'Die Instanz enthält keinen schemaRef auf den Einstiegspunkt der Taxonomie.'], ['Référence à la taxonomie manquante', 'L\'instance ne contient pas de schemaRef vers le point d\'entrée de la taxonomie.']]],
            ['validation.xbrl_missing_context', 'validation_message', 'ALL', ['element', 'context'], [['Contesto mancante', 'Il fatto {{element}} usa il contesto {{context}}, che non è definito.'], ['Context missing', 'Fact {{element}} uses context {{context}}, which is not defined.'], ['Kontext fehlt', 'Der Fakt {{element}} verwendet den Kontext {{context}}, der nicht definiert ist.'], ['Contexte manquant', 'Le fait {{element}} utilise le contexte {{context}}, qui n\'est pas défini.']]],
            ['validation.xbrl_unknown_unit', 'validation_message', 'ALL', ['element', 'unit'], [['Unità di misura sconosciuta', 'Il fatto {{element}} usa l\'unità {{unit}}, che non è definita.'], ['Unknown unit', 'Fact {{element}} uses unit {{unit}}, which is not defined.'], ['Unbekannte Einheit', 'Der Fakt {{element}} verwendet die Einheit {{unit}}, die nicht definiert ist.'], ['Unité inconnue', 'Le fait {{element}} utilise l\'unité {{unit}}, qui n\'est pas définie.']]],
            ['validation.xbrl_duplicate_fact', 'validation_message', 'ALL', ['element', 'context'], [['Fatto duplicato', 'Il fatto {{element}} compare più volte nel contesto {{context}} con valori diversi.'], ['Duplicate fact', 'Fact {{element}} appears more than once in context {{context}} with different values.'], ['Doppelter Fakt', 'Der Fakt {{element}} erscheint mehrfach im Kontext {{context}} mit unterschiedlichen Werten.'], ['Fait en double', 'Le fait {{element}} apparaît plusieurs fois dans le contexte {{context}} avec des valeurs différentes.']]],
            ['validation.xbrl_decimals', 'validation_message', 'ALL', ['element', 'decimals'], [['Precisione non conforme', 'Il fatto {{element}} ha una precisione diversa da quella richiesta dalla modalità di arrotondamento ({{decimals}}).'], ['Precision mismatch', 'Fact {{element}} has a precision different from the one required by the rounding mode ({{decimals}}).'], ['Genauigkeit nicht konform', 'Der Fakt {{element}} hat eine andere Genauigkeit als vom Rundungsmodus verlangt ({{decimals}}).'], ['Précision non conforme', 'Le fait {{element}} a une précision différente de celle exigée par le mode d\'arrondi ({{decimals}}).']]],
            ['validation.unmapped_account', 'validation_message', 'ALL', ['account', 'line'], [['Conto non classificato', 'Il conto {{account}} non ha una regola di riclassificazione: è stato attribuito per natura alla voce {{line}}.'], ['Unmapped account', 'Account {{account}} has no reclassification rule: it was assigned by nature to line {{line}}.'], ['Nicht zugeordnetes Konto', 'Für das Konto {{account}} besteht keine Zuordnungsregel: es wurde nach Art der Position {{line}} zugewiesen.'], ['Compte non classé', 'Le compte {{account}} n\'a pas de règle de reclassement: il a été affecté par nature au poste {{line}}.']]],
            ['validation.rounding_residual', 'validation_message', 'ALL', ['statement', 'residual'], [['Differenza di arrotondamento riallocata', 'Una differenza di {{residual}} nel prospetto {{statement}} è stata imputata alle voci di arrotondamento.'], ['Rounding difference reallocated', 'A difference of {{residual}} in the {{statement}} statement was posted to the rounding lines.'], ['Rundungsdifferenz umgebucht', 'Eine Differenz von {{residual}} in der Rechnung {{statement}} wurde auf die Rundungspositionen gebucht.'], ['Écart d\'arrondi réaffecté', 'Un écart de {{residual}} dans l\'état {{statement}} a été imputé aux postes d\'arrondi.']]],
            ['validation.missing_notes', 'validation_message', 'ALL', [], [['Nota integrativa non compilata', 'Le tabelle quantitative della nota integrativa non sono state ancora compilate.'], ['Notes not populated', 'The quantitative tables of the notes have not been populated yet.'], ['Anhang nicht befüllt', 'Die quantitativen Tabellen des Anhangs wurden noch nicht befüllt.'], ['Annexe non renseignée', 'Les tableaux quantitatifs de l\'annexe n\'ont pas encore été renseignés.']]],
            ['validation.comparative_missing', 'validation_message', 'ALL', [], [['Esercizio comparativo assente', 'Non è stato indicato l\'esercizio precedente: i valori comparativi non saranno esposti.'], ['Comparative year missing', 'No prior year was selected: comparative figures will not be shown.'], ['Vorjahr fehlt', 'Es wurde kein Vorjahr gewählt: Vergleichszahlen werden nicht ausgewiesen.'], ['Exercice comparatif absent', 'Aucun exercice précédent n\'a été choisi: les chiffres comparatifs ne seront pas présentés.']]],
            ['validation.eligibility_override', 'validation_message', 'ALL', ['standard', 'recommended'], [['Schema diverso da quello consigliato', 'Lo schema scelto ({{standard}}) è diverso da quello consentito dai limiti dimensionali ({{recommended}}).'], ['Scheme differs from the recommended one', 'The chosen scheme ({{standard}}) differs from the one allowed by the size thresholds ({{recommended}}).'], ['Abweichendes Schema', 'Das gewählte Schema ({{standard}}) weicht von dem nach den Grössenschwellen zulässigen ab ({{recommended}}).'], ['Schéma différent du schéma recommandé', 'Le schéma choisi ({{standard}}) diffère de celui permis par les seuils dimensionnels ({{recommended}}).']]],
            ['eligibility.micro', 'eligibility_message', 'IT', [], [['Micro-impresa (art. 2435-ter c.c.)', 'L\'impresa non supera due dei tre limiti: attivo {{assets_limit}}, ricavi {{revenue_limit}}, {{employees_limit}} dipendenti medi. Può adottare lo schema delle micro-imprese.'], ['Micro-enterprise (art. 2435-ter Civil Code)', 'The company does not exceed two of three limits: assets {{assets_limit}}, revenue {{revenue_limit}}, {{employees_limit}} average employees. It may adopt the micro-enterprise scheme.'], ['Kleinstunternehmen (Art. 2435-ter ZGB-IT)', 'Die Gesellschaft überschreitet höchstens eine von drei Grenzen: Aktiven {{assets_limit}}, Umsatz {{revenue_limit}}, {{employees_limit}} Mitarbeitende im Schnitt. Das Schema für Kleinstunternehmen ist zulässig.'], ['Micro-entreprise (art. 2435-ter c.c.)', 'L\'entreprise ne dépasse pas deux des trois limites: actif {{assets_limit}}, chiffre d\x27affaires {{revenue_limit}}, {{employees_limit}} salariés en moyenne. Le schéma des micro-entreprises est admis.']]],
            ['eligibility.abbreviated', 'eligibility_message', 'IT', [], [['Bilancio abbreviato (art. 2435-bis c.c.)', 'L\'impresa non supera due dei tre limiti: attivo {{assets_limit}}, ricavi {{revenue_limit}}, {{employees_limit}} dipendenti medi. Può redigere il bilancio in forma abbreviata.'], ['Abbreviated statements (art. 2435-bis Civil Code)', 'The company does not exceed two of three limits: assets {{assets_limit}}, revenue {{revenue_limit}}, {{employees_limit}} average employees. It may prepare abbreviated statements.'], ['Verkürzter Abschluss (Art. 2435-bis ZGB-IT)', 'Die Gesellschaft überschreitet höchstens eine von drei Grenzen: Aktiven {{assets_limit}}, Umsatz {{revenue_limit}}, {{employees_limit}} Mitarbeitende im Schnitt. Ein verkürzter Abschluss ist zulässig.'], ['Comptes abrégés (art. 2435-bis c.c.)', 'L\'entreprise ne dépasse pas deux des trois limites: actif {{assets_limit}}, chiffre d\x27affaires {{revenue_limit}}, {{employees_limit}} salariés en moyenne. Des comptes abrégés sont admis.']]],
            ['eligibility.ordinary', 'eligibility_message', 'IT', [], [['Bilancio ordinario (artt. 2424-2425 c.c.)', 'L\'impresa supera due dei tre limiti del bilancio abbreviato: deve redigere il bilancio in forma ordinaria.'], ['Ordinary statements (arts. 2424-2425 Civil Code)', 'The company exceeds two of the three limits of abbreviated statements: ordinary statements are required.'], ['Ordentlicher Abschluss (Art. 2424-2425 ZGB-IT)', 'Die Gesellschaft überschreitet zwei der drei Grenzen des verkürzten Abschlusses: ein ordentlicher Abschluss ist erforderlich.'], ['Comptes ordinaires (art. 2424-2425 c.c.)', 'L\'entreprise dépasse deux des trois limites des comptes abrégés: des comptes ordinaires sont requis.']]],
            ['eligibility.ch_co', 'eligibility_message', 'CH', [], [['Conto annuale CO (art. 957 segg.)', 'Le società con obbligo di iscrizione al registro di commercio redigono conto annuale con bilancio, conto economico e allegato (art. 958 CO). Sopra i limiti dell\'art. 727 CO si aggiungono rendiconto finanziario e rapporto sull\'andamento degli affari (art. 961 CO).'], ['Annual accounts under the CO (art. 957 et seq.)', 'Companies registered in the commercial register prepare annual accounts with balance sheet, income statement and notes (art. 958 CO). Above the thresholds of art. 727 CO a cash flow statement and a management report are added (art. 961 CO).'], ['Jahresrechnung nach OR (Art. 957 ff.)', 'Im Handelsregister eingetragene Gesellschaften erstellen die Jahresrechnung aus Bilanz, Erfolgsrechnung und Anhang (Art. 958 OR). Oberhalb der Grenzen von Art. 727 OR kommen Geldflussrechnung und Lagebericht hinzu (Art. 961 OR).'], ['Comptes annuels selon le CO (art. 957 ss)', 'Les sociétés inscrites au registre du commerce établissent des comptes annuels avec bilan, compte de résultat et annexe (art. 958 CO). Au-delà des seuils de l\'art. 727 CO s\'ajoutent un tableau de flux de trésorerie et un rapport de gestion (art. 961 CO).']]],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: list<string>, 4: list<array{0: string, 1: string}>}>
     */
    private static function reportLabels(): array
    {
        $labels = [
            'report.dossier_title' => ['Fascicolo di bilancio', 'Financial statement dossier', 'Abschlussdossier', 'Dossier des comptes annuels'],
            'report.balance_sheet' => ['Stato patrimoniale', 'Balance sheet', 'Bilanz', 'Bilan'],
            'report.income_statement' => ['Conto economico', 'Income statement', 'Erfolgsrechnung', 'Compte de résultat'],
            'report.cash_flow' => ['Rendiconto finanziario', 'Cash flow statement', 'Geldflussrechnung', 'Tableau des flux de trésorerie'],
            'report.notes_it' => ['Nota integrativa', 'Notes to the financial statements', 'Nota integrativa (Anhang IT)', 'Annexe (Nota integrativa)'],
            'report.notes_ch' => ['Allegato (Anhang)', 'Notes (Anhang)', 'Anhang', 'Annexe'],
            'report.ratios' => ['Indici di bilancio e indicatori di crisi', 'Financial ratios and crisis indicators', 'Kennzahlen und Krisenindikatoren', 'Ratios financiers et indicateurs de crise'],
            'report.validation' => ['Esito della validazione', 'Validation outcome', 'Validierungsergebnis', 'Résultat de la validation'],
            'report.profit_allocation' => ['Proposta di destinazione del risultato', 'Proposal for the allocation of the result', 'Antrag zur Ergebnisverwendung', 'Proposition d\'affectation du résultat'],
            'report.comparative' => ['Esercizio precedente', 'Prior year', 'Vorjahr', 'Exercice précédent'],
            'report.current' => ['Esercizio', 'Current year', 'Geschäftsjahr', 'Exercice'],
            'report.pdfa_notice' => ['Documento conforme PDF/A per la conservazione a lungo termine', 'PDF/A document for long-term archiving', 'PDF/A-Dokument zur Langzeitarchivierung', 'Document PDF/A pour l\'archivage à long terme'],
            'report.ministerial_preview' => ['Anteprima in formato ministeriale (TEBENI)', 'Ministerial-style preview (TEBENI)', 'Vorschau im Behördenformat (TEBENI)', 'Aperçu au format ministériel (TEBENI)'],
            'rounding.reserve_label' => ['Riserva da arrotondamento', 'Rounding reserve', 'Rundungsreserve', 'Réserve d\'arrondi'],
            'rounding.income_label' => ['Arrotondamenti attivi', 'Rounding gains', 'Rundungsgewinne', 'Arrondis positifs'],
            'rounding.cost_label' => ['Arrotondamenti passivi', 'Rounding losses', 'Rundungsverluste', 'Arrondis négatifs'],
            'bifront.asset_side' => ['Saldo dare: esposto all\'attivo', 'Debit balance: shown under assets', 'Sollsaldo: unter Aktiven ausgewiesen', 'Solde débiteur: présenté à l\'actif'],
            'allocation.legal_reserve' => ['Riserva legale', 'Legal reserve', 'Gesetzliche Gewinnreserve', 'Réserve légale'],
            'allocation.dividends' => ['Dividendi', 'Dividends', 'Dividende', 'Dividendes'],
            'allocation.other_reserves' => ['Altre riserve', 'Other reserves', 'Übrige Reserven', 'Autres réserves'],
            'allocation.carried_forward' => ['Utili a nuovo', 'Retained to next year', 'Vortrag auf neue Rechnung', 'Report à nouveau'],
            'allocation.loss_carried' => ['Perdita portata a nuovo', 'Loss carried forward', 'Verlustvortrag', 'Perte reportée'],
            'allocation.none' => ['Nessuna destinazione proposta', 'No allocation proposed', 'Keine Verwendung beantragt', 'Aucune affectation proposée'],
            'bifront.liability_side' => ['Saldo avere: esposto al passivo', 'Credit balance: shown under liabilities', 'Habensaldo: unter Passiven ausgewiesen', 'Solde créditeur: présenté au passif'],
        ];

        $rows = [];

        foreach ($labels as $key => $texts) {
            $rows[] = [$key, 'report_label', 'ALL', [], array_map(static fn (string $text): array => [$text, $text], $texts)];
        }

        return $rows;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: list<string>, 4: list<array{0: string, 1: string}>}>
     */
    private static function standards(): array
    {
        $standards = [
            'IT_OIC_Ordinario' => ['IT', ['Bilancio ordinario (OIC)', 'Schema degli artt. 2424-2425 c.c. con nota integrativa completa, rendiconto finanziario (OIC 10) e relazione sulla gestione.'], ['Ordinary statements (OIC)', 'Scheme of arts. 2424-2425 Civil Code with full notes, cash flow statement (OIC 10) and management report.'], ['Ordentlicher Abschluss (OIC)', 'Schema nach Art. 2424-2425 ZGB-IT mit vollständigem Anhang, Geldflussrechnung (OIC 10) und Lagebericht.'], ['Comptes ordinaires (OIC)', 'Schéma des art. 2424-2425 c.c. avec annexe complète, tableau des flux (OIC 10) et rapport de gestion.']],
            'IT_OIC_Abbreviato' => ['IT', ['Bilancio abbreviato (OIC)', 'Schema dell\'art. 2435-bis c.c.: voci accorpate, nota integrativa ridotta, rendiconto finanziario non obbligatorio.'], ['Abbreviated statements (OIC)', 'Scheme of art. 2435-bis Civil Code: grouped lines, reduced notes, cash flow statement optional.'], ['Verkürzter Abschluss (OIC)', 'Schema nach Art. 2435-bis ZGB-IT: zusammengefasste Posten, reduzierter Anhang, Geldflussrechnung optional.'], ['Comptes abrégés (OIC)', 'Schéma de l\'art. 2435-bis c.c.: postes regroupés, annexe réduite, tableau des flux facultatif.']],
            'IT_OIC_Micro' => ['IT', ['Bilancio delle micro-imprese (OIC)', 'Schema dell\'art. 2435-ter c.c.: stato patrimoniale e conto economico abbreviati, senza rendiconto finanziario, nota integrativa e relazione sulla gestione se le informazioni sono in calce.'], ['Micro-enterprise statements (OIC)', 'Scheme of art. 2435-ter Civil Code: abbreviated balance sheet and income statement, no cash flow statement; notes and management report omitted when the information is at the foot of the balance sheet.'], ['Abschluss für Kleinstunternehmen (OIC)', 'Schema nach Art. 2435-ter ZGB-IT: verkürzte Bilanz und Erfolgsrechnung ohne Geldflussrechnung; Anhang und Lagebericht entfallen, wenn die Angaben unter der Bilanz stehen.'], ['Comptes des micro-entreprises (OIC)', 'Schéma de l\'art. 2435-ter c.c.: bilan et compte de résultat abrégés, sans tableau des flux; annexe et rapport de gestion omis si les informations figurent en bas de bilan.']],
            'CH_CO_Art959' => ['CH', ['Conto annuale CO art. 957-963b', 'Bilancio minimo (art. 959a), conto economico (art. 959b, costo complessivo o costo del venduto) e allegato (art. 959c).'], ['Annual accounts CO art. 957-963b', 'Minimum balance sheet (art. 959a), income statement (art. 959b, total cost or cost of sales method) and notes (art. 959c).'], ['Jahresrechnung OR Art. 957-963b', 'Mindestgliederung der Bilanz (Art. 959a), Erfolgsrechnung (Art. 959b, Gesamtkosten- oder Umsatzkostenverfahren) und Anhang (Art. 959c).'], ['Comptes annuels CO art. 957-963b', 'Bilan minimal (art. 959a), compte de résultat (art. 959b, méthode des charges par nature ou par fonction) et annexe (art. 959c).']],
            'CH_SwissGaapFer' => ['CH', ['Swiss GAAP FER (FER 1-31)', 'Presentazione secondo le Swiss GAAP FER con true and fair view, rendiconto finanziario (FER 4), variazioni del capitale proprio (FER 24) e allegato; nessuna riserva latente.'], ['Swiss GAAP FER (FER 1-31)', 'Presentation under Swiss GAAP FER with true and fair view, cash flow statement (FER 4), statement of changes in equity (FER 24) and notes; no hidden reserves.'], ['Swiss GAAP FER (FER 1-31)', 'Darstellung nach Swiss GAAP FER mit True and Fair View, Geldflussrechnung (FER 4), Eigenkapitalnachweis (FER 24) und Anhang; keine stillen Reserven.'], ['Swiss GAAP RPC (RPC 1-31)', 'Présentation selon les Swiss GAAP RPC avec image fidèle, tableau de flux (RPC 4), variation des fonds propres (RPC 24) et annexe; pas de réserves latentes.']],
        ];

        $rows = [];

        foreach ($standards as $code => [$family, $it, $en, $de, $fr]) {
            $rows[] = ['standard.'.$code, 'standard', $family, [], [$it, $en, $de, $fr]];
        }

        return $rows;
    }
}
