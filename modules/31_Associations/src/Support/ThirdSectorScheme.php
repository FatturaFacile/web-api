<?php

declare(strict_types=1);

namespace Modules\Associations\Support;

/**
 * The statement schemes of third-sector bodies adopted by the decree of the Minister of Labour and Social Policies of
 * 5 March 2020 (Official Gazette no. 102 of 18 April 2020): balance sheet (Mod. A), management report (Mod. B), mission
 * report (Mod. C) and cash report (Mod. D). Lines and headings follow the text published by the Ministry; which ledger
 * account feeds a line is configured per company in the mapping table.
 *
 * Line codes: A-AS assets and A-LI liabilities of Mod. A; B-C costs and B-R revenues of Mod. B; D-O outflows and D-I
 * inflows of Mod. D. The letter and number after the prefix are those of the decree. Lines computed by the statement
 * (results, totals) cannot be mapped.
 */
final class ThirdSectorScheme
{
    public const string Source = 'D.M. Ministero del Lavoro e delle Politiche Sociali 5 marzo 2020, G.U. n. 102 del 18 aprile 2020';

    /**
     * Lines of the balance sheet. The result of the year (A-LI.A.IV) is computed from the management report.
     *
     * @var array<string, string>
     */
    public const array BalanceSheet = [
        'A-AS.A' => 'Quote associative o apporti ancora dovuti',
        'A-AS.B.I.1' => 'Costi di impianto e di ampliamento',
        'A-AS.B.I.2' => 'Costi di sviluppo',
        'A-AS.B.I.3' => 'Diritti di brevetto industriale e diritti di utilizzazione delle opere dell\'ingegno',
        'A-AS.B.I.4' => 'Concessioni, licenze, marchi e diritti simili',
        'A-AS.B.I.5' => 'Avviamento',
        'A-AS.B.I.6' => 'Immobilizzazioni immateriali in corso e acconti',
        'A-AS.B.I.7' => 'Altre immobilizzazioni immateriali',
        'A-AS.B.II.1' => 'Terreni e fabbricati',
        'A-AS.B.II.2' => 'Impianti e macchinari',
        'A-AS.B.II.3' => 'Attrezzature',
        'A-AS.B.II.4' => 'Altri beni',
        'A-AS.B.II.5' => 'Immobilizzazioni materiali in corso e acconti',
        'A-AS.B.III.1.a' => 'Partecipazioni in imprese controllate',
        'A-AS.B.III.1.b' => 'Partecipazioni in imprese collegate',
        'A-AS.B.III.1.c' => 'Partecipazioni in altre imprese',
        'A-AS.B.III.2.a' => 'Crediti immobilizzati verso imprese controllate',
        'A-AS.B.III.2.b' => 'Crediti immobilizzati verso imprese collegate',
        'A-AS.B.III.2.c' => 'Crediti immobilizzati verso altri enti del Terzo settore',
        'A-AS.B.III.2.d' => 'Crediti immobilizzati verso altri',
        'A-AS.B.III.3' => 'Altri titoli',
        'A-AS.C.I.1' => 'Materie prime, sussidiarie e di consumo',
        'A-AS.C.I.2' => 'Prodotti in corso di lavorazione e semilavorati',
        'A-AS.C.I.3' => 'Lavori in corso su ordinazione',
        'A-AS.C.I.4' => 'Prodotti finiti e merci',
        'A-AS.C.I.5' => 'Acconti',
        'A-AS.C.II.1' => 'Crediti verso utenti e clienti',
        'A-AS.C.II.2' => 'Crediti verso associati e fondatori',
        'A-AS.C.II.3' => 'Crediti verso enti pubblici',
        'A-AS.C.II.4' => 'Crediti verso soggetti privati per contributi',
        'A-AS.C.II.5' => 'Crediti verso enti della stessa rete associativa',
        'A-AS.C.II.6' => 'Crediti verso altri enti del Terzo settore',
        'A-AS.C.II.7' => 'Crediti verso imprese controllate',
        'A-AS.C.II.8' => 'Crediti verso imprese collegate',
        'A-AS.C.II.9' => 'Crediti tributari',
        'A-AS.C.II.10' => 'Crediti da 5 per mille',
        'A-AS.C.II.11' => 'Imposte anticipate',
        'A-AS.C.II.12' => 'Crediti verso altri',
        'A-AS.C.III.1' => 'Partecipazioni in imprese controllate (attivo circolante)',
        'A-AS.C.III.2' => 'Partecipazioni in imprese collegate (attivo circolante)',
        'A-AS.C.III.3' => 'Altri titoli (attivo circolante)',
        'A-AS.C.IV.1' => 'Depositi bancari e postali',
        'A-AS.C.IV.2' => 'Assegni',
        'A-AS.C.IV.3' => 'Danaro e valori in cassa',
        'A-AS.D' => 'Ratei e risconti attivi',
        'A-LI.A.I' => 'Fondo di dotazione dell\'ente',
        'A-LI.A.II.1' => 'Riserve statutarie',
        'A-LI.A.II.2' => 'Riserve vincolate per decisione degli organi istituzionali',
        'A-LI.A.II.3' => 'Riserve vincolate destinate da terzi',
        'A-LI.A.III.1' => 'Riserve di utili o avanzi di gestione',
        'A-LI.A.III.2' => 'Altre riserve',
        'A-LI.B.1' => 'Fondi per trattamento di quiescenza e obblighi simili',
        'A-LI.B.2' => 'Fondi per imposte, anche differite',
        'A-LI.B.3' => 'Altri fondi per rischi e oneri',
        'A-LI.C' => 'Trattamento di fine rapporto di lavoro subordinato',
        'A-LI.D.1' => 'Debiti verso banche',
        'A-LI.D.2' => 'Debiti verso altri finanziatori',
        'A-LI.D.3' => 'Debiti verso associati e fondatori per finanziamenti',
        'A-LI.D.4' => 'Debiti verso enti della stessa rete associativa',
        'A-LI.D.5' => 'Debiti per erogazioni liberali condizionate',
        'A-LI.D.6' => 'Acconti',
        'A-LI.D.7' => 'Debiti verso fornitori',
        'A-LI.D.8' => 'Debiti verso imprese controllate e collegate',
        'A-LI.D.9' => 'Debiti tributari',
        'A-LI.D.10' => 'Debiti verso istituti di previdenza e di sicurezza sociale',
        'A-LI.D.11' => 'Debiti verso dipendenti e collaboratori',
        'A-LI.D.12' => 'Altri debiti',
        'A-LI.E' => 'Ratei e risconti passivi',
    ];

    public const string ResultLine = 'A-LI.A.IV';

    public const string RetainedResultsLine = 'A-LI.A.III.1';

    /**
     * Liquidity lines of the balance sheet: their accounts are the cash and bank of the cash report.
     */
    public const array LiquidityLines = ['A-AS.C.IV.1', 'A-AS.C.IV.2', 'A-AS.C.IV.3'];

    /**
     * Lines of the management report. Optional figurative costs and revenues are not produced.
     *
     * @var array<string, string>
     */
    public const array ManagementReport = [
        'B-C.A.1' => 'Materie prime, sussidiarie, di consumo e di merci',
        'B-C.A.2' => 'Servizi',
        'B-C.A.3' => 'Godimento beni di terzi',
        'B-C.A.4' => 'Personale',
        'B-C.A.5' => 'Ammortamenti',
        'B-C.A.6' => 'Accantonamenti per rischi ed oneri',
        'B-C.A.7' => 'Oneri diversi di gestione',
        'B-C.A.8' => 'Rimanenze iniziali',
        'B-C.B.1' => 'Materie prime, sussidiarie, di consumo e di merci',
        'B-C.B.2' => 'Servizi',
        'B-C.B.3' => 'Godimento beni di terzi',
        'B-C.B.4' => 'Personale',
        'B-C.B.5' => 'Ammortamenti',
        'B-C.B.6' => 'Accantonamenti per rischi ed oneri',
        'B-C.B.7' => 'Oneri diversi di gestione',
        'B-C.B.8' => 'Rimanenze iniziali',
        'B-C.C.1' => 'Oneri per raccolte fondi abituali',
        'B-C.C.2' => 'Oneri per raccolte fondi occasionali',
        'B-C.C.3' => 'Altri oneri',
        'B-C.D.1' => 'Su rapporti bancari',
        'B-C.D.2' => 'Su prestiti',
        'B-C.D.3' => 'Da patrimonio edilizio',
        'B-C.D.4' => 'Da altri beni patrimoniali',
        'B-C.D.5' => 'Accantonamenti per rischi ed oneri',
        'B-C.D.6' => 'Altri oneri',
        'B-C.E.1' => 'Materie prime, sussidiarie, di consumo e di merci',
        'B-C.E.2' => 'Servizi',
        'B-C.E.3' => 'Godimento beni di terzi',
        'B-C.E.4' => 'Personale',
        'B-C.E.5' => 'Ammortamenti',
        'B-C.E.6' => 'Accantonamenti per rischi ed oneri',
        'B-C.E.7' => 'Altri oneri',
        'B-C.T' => 'Imposte',
        'B-R.A.1' => 'Proventi da quote associative e apporti dei fondatori',
        'B-R.A.2' => 'Proventi dagli associati per attività mutuali',
        'B-R.A.3' => 'Ricavi per prestazioni e cessioni ad associati e fondatori',
        'B-R.A.4' => 'Erogazioni liberali',
        'B-R.A.5' => 'Proventi del 5 per mille',
        'B-R.A.6' => 'Contributi da soggetti privati',
        'B-R.A.7' => 'Ricavi per prestazioni e cessioni a terzi',
        'B-R.A.8' => 'Contributi da enti pubblici',
        'B-R.A.9' => 'Proventi da contratti con enti pubblici',
        'B-R.A.10' => 'Altri ricavi, rendite e proventi',
        'B-R.A.11' => 'Rimanenze finali',
        'B-R.B.1' => 'Ricavi per prestazioni e cessioni ad associati e fondatori',
        'B-R.B.2' => 'Contributi da soggetti privati',
        'B-R.B.3' => 'Ricavi per prestazioni e cessioni a terzi',
        'B-R.B.4' => 'Contributi da enti pubblici',
        'B-R.B.5' => 'Proventi da contratti con enti pubblici',
        'B-R.B.6' => 'Altri ricavi, rendite e proventi',
        'B-R.B.7' => 'Rimanenze finali',
        'B-R.C.1' => 'Proventi da raccolte fondi abituali',
        'B-R.C.2' => 'Proventi da raccolte fondi occasionali',
        'B-R.C.3' => 'Altri proventi',
        'B-R.D.1' => 'Da rapporti bancari',
        'B-R.D.2' => 'Da altri investimenti finanziari',
        'B-R.D.3' => 'Da patrimonio edilizio',
        'B-R.D.4' => 'Da altri beni patrimoniali',
        'B-R.D.5' => 'Altri proventi',
        'B-R.E.1' => 'Proventi da distacco del personale',
        'B-R.E.2' => 'Altri proventi di supporto generale',
    ];

    /**
     * Sections of the management report: the result of each is revenues minus costs of the same letter.
     *
     * @var array<string, string>
     */
    public const array ManagementSections = [
        'A' => 'Attività di interesse generale',
        'B' => 'Attività diverse',
        'C' => 'Attività di raccolta fondi',
        'D' => 'Attività finanziarie e patrimoniali',
        'E' => 'Supporto generale',
    ];

    /**
     * Lines of the cash report. The section INV holds investments, divestments and financing.
     *
     * @var array<string, string>
     */
    public const array CashReport = [
        'D-O.A.1' => 'Materie prime, sussidiarie, di consumo e di merci',
        'D-O.A.2' => 'Servizi',
        'D-O.A.3' => 'Godimento beni di terzi',
        'D-O.A.4' => 'Personale',
        'D-O.A.5' => 'Uscite diverse di gestione',
        'D-O.B.1' => 'Materie prime, sussidiarie, di consumo e di merci',
        'D-O.B.2' => 'Servizi',
        'D-O.B.3' => 'Godimento beni di terzi',
        'D-O.B.4' => 'Personale',
        'D-O.B.5' => 'Uscite diverse di gestione',
        'D-O.C.1' => 'Uscite per raccolte fondi abituali',
        'D-O.C.2' => 'Uscite per raccolte fondi occasionali',
        'D-O.C.3' => 'Altre uscite',
        'D-O.D.1' => 'Su rapporti bancari',
        'D-O.D.2' => 'Su investimenti finanziari',
        'D-O.D.3' => 'Su patrimonio edilizio',
        'D-O.D.4' => 'Su altri beni patrimoniali',
        'D-O.D.5' => 'Altre uscite',
        'D-O.E.1' => 'Materie prime, sussidiarie, di consumo e di merci',
        'D-O.E.2' => 'Servizi',
        'D-O.E.3' => 'Godimento beni di terzi',
        'D-O.E.4' => 'Personale',
        'D-O.E.5' => 'Altre uscite',
        'D-O.T' => 'Imposte',
        'D-O.INV.1' => 'Investimenti in immobilizzazioni inerenti alle attività di interesse generale',
        'D-O.INV.2' => 'Investimenti in immobilizzazioni inerenti alle attività diverse',
        'D-O.INV.3' => 'Investimenti in attività finanziarie e patrimoniali',
        'D-O.INV.4' => 'Rimborso di finanziamenti per quota capitale e di prestiti',
        'D-I.A.1' => 'Entrate da quote associative e apporti dei fondatori',
        'D-I.A.2' => 'Entrate dagli associati per attività mutuali',
        'D-I.A.3' => 'Entrate per prestazioni e cessioni ad associati e fondatori',
        'D-I.A.4' => 'Erogazioni liberali',
        'D-I.A.5' => 'Entrate del 5 per mille',
        'D-I.A.6' => 'Contributi da soggetti privati',
        'D-I.A.7' => 'Entrate per prestazioni e cessioni a terzi',
        'D-I.A.8' => 'Contributi da enti pubblici',
        'D-I.A.9' => 'Entrate da contratti con enti pubblici',
        'D-I.A.10' => 'Altre entrate',
        'D-I.B.1' => 'Entrate per prestazioni e cessioni ad associati e fondatori',
        'D-I.B.2' => 'Contributi da soggetti privati',
        'D-I.B.3' => 'Entrate per prestazioni e cessioni a terzi',
        'D-I.B.4' => 'Contributi da enti pubblici',
        'D-I.B.5' => 'Entrate da contratti con enti pubblici',
        'D-I.B.6' => 'Altre entrate',
        'D-I.C.1' => 'Entrate da raccolte fondi abituali',
        'D-I.C.2' => 'Entrate da raccolte fondi occasionali',
        'D-I.C.3' => 'Altre entrate',
        'D-I.D.1' => 'Da rapporti bancari',
        'D-I.D.2' => 'Da altri investimenti finanziari',
        'D-I.D.3' => 'Da patrimonio edilizio',
        'D-I.D.4' => 'Da altri beni patrimoniali',
        'D-I.D.5' => 'Altre entrate',
        'D-I.E.1' => 'Entrate da distacco del personale',
        'D-I.E.2' => 'Altre entrate di supporto generale',
        'D-I.INV.1' => 'Disinvestimenti di immobilizzazioni inerenti alle attività di interesse generale',
        'D-I.INV.2' => 'Disinvestimenti di immobilizzazioni inerenti alle attività diverse',
        'D-I.INV.3' => 'Disinvestimenti di attività finanziarie e patrimoniali',
        'D-I.INV.4' => 'Ricevimento di finanziamenti e di prestiti',
    ];

    /**
     * The points the mission report has to cover, in the order of the decree (short headings).
     *
     * @var array<int, string>
     */
    public const array MissionReportPoints = [
        1 => 'Informazioni generali, missione, attività di interesse generale, sezione RUNTS, regime fiscale, sedi',
        2 => 'Associati o fondatori, attività svolte nei loro confronti e partecipazione alla vita dell\'ente',
        3 => 'Criteri di valutazione e accorpamenti o eliminazioni di voci rispetto al modello',
        4 => 'Movimenti delle immobilizzazioni',
        5 => 'Costi di impianto, di ampliamento e di sviluppo',
        6 => 'Crediti e debiti oltre cinque anni e debiti assistiti da garanzie reali',
        7 => 'Ratei, risconti e altri fondi',
        8 => 'Movimentazioni del patrimonio netto, vincoli e utilizzi',
        9 => 'Impegni di spesa o di reinvestimento di fondi con finalità specifiche',
        10 => 'Debiti per erogazioni liberali condizionate',
        11 => 'Analisi delle principali componenti del rendiconto gestionale',
        12 => 'Natura delle erogazioni liberali ricevute',
        13 => 'Numero medio dei dipendenti e numero dei volontari iscritti nel registro',
        14 => 'Compensi dell\'organo esecutivo, dell\'organo di controllo e del revisore',
        15 => 'Patrimoni destinati a uno specifico affare',
        16 => 'Operazioni con parti correlate',
        17 => 'Proposta di destinazione dell\'avanzo o di copertura del disavanzo',
        18 => 'Situazione dell\'ente e andamento della gestione',
        19 => 'Evoluzione prevedibile della gestione',
        20 => 'Modalità di perseguimento delle finalità statutarie',
        21 => 'Contributo e carattere secondario e strumentale delle attività diverse',
        22 => 'Costi e proventi figurativi, se esposti',
        23 => 'Differenza retributiva tra lavoratori dipendenti',
        24 => 'Attività di raccolta fondi e rendiconto delle raccolte occasionali',
    ];

    /**
     * @return array<string, string>
     */
    public static function lines(string $scheme): array
    {
        return match ($scheme) {
            'A' => self::BalanceSheet,
            'B' => self::ManagementReport,
            'D' => self::CashReport,
            default => [],
        };
    }

    public static function isLine(string $scheme, string $code): bool
    {
        return array_key_exists($code, self::lines($scheme));
    }

    /**
     * The section letter of a line of Mod. B or Mod. D, or "T" for taxes and "INV" for investments.
     */
    public static function section(string $code): string
    {
        $parts = explode('.', $code);

        return $parts[1] ?? '';
    }

    public static function isRevenue(string $code): bool
    {
        return str_starts_with($code, 'B-R.') || str_starts_with($code, 'D-I.');
    }

    public static function isAsset(string $code): bool
    {
        return str_starts_with($code, 'A-AS.');
    }
}
