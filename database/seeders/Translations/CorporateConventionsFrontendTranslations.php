<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the corporate conventions module (Mod 358): the conventions list and the tabbed form, the ROI dashboard, the split
 * billing and welfare reconciliation centre, the till widget and the public landing page /convenzioni/<slug>, plus the codes the API
 * reports (corporateConventions.error.<code>, taken from lang/<locale>/corporate_conventions.php so there is one source for each text).
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Legal acronyms (SDI, PEC, AVS, LPP, QR-IBAN, P/IVA, CF, UID/IDI, CRAL, TUIR, OTP, VDT, D.Lgs.) stay untranslated.
 */
final class CorporateConventionsFrontendTranslations
{
    private const array LOCALES = ['it', 'en', 'de', 'fr'];

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::navigation(),
            self::enumerations(),
            self::errors(),
            self::fields(),
            self::columns(),
            self::actions(),
            self::sections(),
            self::hints(),
            self::dashboard(),
            self::till(),
            self::landing(),
            self::messages(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.CORPORATE_CONVENTIONS' => ['Convenzioni aziendali', 'Corporate conventions', 'Firmenkonventionen', 'Conventions d\'entreprise'],
            'NAV.CORPORATE_CONVENTIONS_DASHBOARD' => ['Cruscotto ROI', 'ROI dashboard', 'ROI-Dashboard', 'Tableau de bord ROI'],
            'NAV.CORPORATE_CONVENTIONS_LIST' => ['Convenzioni', 'Conventions', 'Konventionen', 'Conventions'],
            'NAV.CORPORATE_CONVENTIONS_BILLING' => ['Fatturazione e riconciliazione', 'Billing and reconciliation', 'Fakturierung und Abstimmung', 'Facturation et rapprochement'],
            'PAGE_TITLE.CORPORATE_CONVENTIONS' => ['Convenzioni aziendali e sconti dipendenti', 'Corporate conventions and employee discounts', 'Firmenkonventionen und Mitarbeiterrabatte', 'Conventions d\'entreprise et remises employés'],
            'PAGE_TITLE.CORPORATE_CONVENTIONS_DASHBOARD' => ['Cruscotto ROI delle convenzioni', 'Conventions ROI dashboard', 'ROI-Dashboard der Konventionen', 'Tableau de bord ROI des conventions'],
            'PAGE_TITLE.CORPORATE_CONVENTIONS_BILLING' => ['Fatturazione quote ente e riconciliazione buoni welfare', 'Employer quota billing and welfare voucher reconciliation', 'Fakturierung der Arbeitgeberanteile und Abstimmung der Welfare-Gutscheine', 'Facturation des quotes-parts employeur et rapprochement des bons welfare'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        return [
            'corporateConventions.partnerType.CORPORATE' => ['Azienda', 'Company', 'Unternehmen', 'Entreprise'],
            'corporateConventions.partnerType.CRAL' => ['CRAL / circolo aziendale', 'CRAL / staff club', 'CRAL / Personalverein', 'CRAL / cercle du personnel'],
            'corporateConventions.partnerType.PROFESSIONAL_ORDER' => ['Ordine professionale', 'Professional order', 'Berufsverband', 'Ordre professionnel'],
            'corporateConventions.partnerType.UNION' => ['Sindacato', 'Trade union', 'Gewerkschaft', 'Syndicat'],
            'corporateConventions.partnerType.UNIVERSITY' => ['Università', 'University', 'Universität', 'Université'],
            'corporateConventions.partnerType.PUBLIC_BODY' => ['Ente pubblico', 'Public body', 'Öffentliche Körperschaft', 'Organisme public'],
            'corporateConventions.partnerType.INSURANCE' => ['Assicurazione', 'Insurance', 'Versicherung', 'Assurance'],
            'corporateConventions.partnerType.ASSOCIATION' => ['Associazione', 'Association', 'Verein', 'Association'],

            'corporateConventions.status.DRAFT' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'corporateConventions.status.ACTIVE' => ['Attiva', 'Active', 'Aktiv', 'Active'],
            'corporateConventions.status.SUSPENDED' => ['Sospesa', 'Suspended', 'Ausgesetzt', 'Suspendue'],
            'corporateConventions.status.EXPIRED' => ['Scaduta', 'Expired', 'Abgelaufen', 'Expirée'],

            'corporateConventions.verification.WORK_EMAIL_OTP' => ['E-mail aziendale con codice OTP', 'Work e-mail with OTP code', 'Firmen-E-Mail mit OTP-Code', 'E-mail professionnelle avec code OTP'],
            'corporateConventions.verification.MEMBERSHIP_CARD_PREFIX' => ['Tessera con prefisso / formato', 'Membership card with prefix / pattern', 'Mitgliedskarte mit Präfix / Muster', 'Carte de membre avec préfixe / format'],
            'corporateConventions.verification.HASHED_EMPLOYEE_LIST' => ['Elenco matricole (SHA-256)', 'Employee list (SHA-256)', 'Mitarbeiterliste (SHA-256)', 'Liste des employés (SHA-256)'],
            'corporateConventions.verification.PROMO_CODE' => ['Codice convenzione', 'Convention code', 'Konventionscode', 'Code de convention'],

            'corporateConventions.ruleType.PRICE_LIST' => ['Listino dedicato', 'Dedicated price list', 'Eigene Preisliste', 'Liste de prix dédiée'],
            'corporateConventions.ruleType.CATEGORY_PERCENTAGE' => ['% per categoria', '% by category', '% je Kategorie', '% par catégorie'],
            'corporateConventions.ruleType.PRODUCT_FAMILY_PERCENTAGE' => ['% per famiglia prodotti', '% by product family', '% je Produktfamilie', '% par famille de produits'],
            'corporateConventions.ruleType.GLOBAL_PERCENTAGE' => ['% su tutto il carrello', '% on the whole cart', '% auf den ganzen Warenkorb', '% sur tout le panier'],

            'corporateConventions.subsidyType.FIXED_AMOUNT' => ['Importo fisso', 'Fixed amount', 'Fester Betrag', 'Montant fixe'],
            'corporateConventions.subsidyType.PERCENTAGE' => ['Percentuale', 'Percentage', 'Prozentsatz', 'Pourcentage'],
            'corporateConventions.subsidyPurpose.DLGS_81_08_VDT' => ['D.Lgs. 81/08 - occhiali per videoterminalisti (VDT)', 'D.Lgs. 81/08 - eyewear for screen workers (VDT)', 'D.Lgs. 81/08 - Brillen für Bildschirmarbeit (VDT)', 'D.Lgs. 81/08 - lunettes pour travail sur écran (VDT)'],
            'corporateConventions.subsidyPurpose.PREVENTIVE_HEALTHCARE' => ['Prevenzione sanitaria', 'Preventive healthcare', 'Gesundheitsvorsorge', 'Prévention santé'],
            'corporateConventions.subsidyPurpose.SPORTS_WELLNESS' => ['Sport e benessere', 'Sports and wellness', 'Sport und Wellness', 'Sport et bien-être'],
            'corporateConventions.subsidyPurpose.GENERAL' => ['Generico', 'General', 'Allgemein', 'Général'],
            'corporateConventions.billingCycle.MONTHLY' => ['Mensile', 'Monthly', 'Monatlich', 'Mensuel'],
            'corporateConventions.billingCycle.QUARTERLY' => ['Trimestrale', 'Quarterly', 'Vierteljährlich', 'Trimestriel'],
            'corporateConventions.billingCycle.IMMEDIATE' => ['Immediata', 'Immediate', 'Sofort', 'Immédiat'],

            'corporateConventions.beneficiaryType.PRIMARY_EMPLOYEE' => ['Dipendente', 'Employee', 'Mitarbeitende', 'Employé'],
            'corporateConventions.beneficiaryType.FAMILY_MEMBER' => ['Familiare', 'Family member', 'Familienmitglied', 'Membre de la famille'],
            'corporateConventions.relationship.SELF' => ['Titolare', 'Holder', 'Inhaber', 'Titulaire'],
            'corporateConventions.relationship.SPOUSE' => ['Coniuge', 'Spouse', 'Ehepartner', 'Conjoint'],
            'corporateConventions.relationship.CHILD' => ['Figlio/a', 'Child', 'Kind', 'Enfant'],
            'corporateConventions.relationship.PARENT' => ['Genitore', 'Parent', 'Elternteil', 'Parent'],

            'corporateConventions.jurisdiction.IT_ART51_TUIR' => ['Italia - welfare aziendale Art. 51 TUIR', 'Italy - corporate welfare Art. 51 TUIR', 'Italien - Corporate Welfare Art. 51 TUIR', 'Italie - welfare d\'entreprise Art. 51 TUIR'],
            'corporateConventions.jurisdiction.CH_REKA_LUNCHCHECK' => ['Svizzera - Reka-Check e Lunch-Check', 'Switzerland - Reka cheques and Lunch-Check', 'Schweiz - Reka-Checks und Lunch-Check', 'Suisse - chèques Reka et Lunch-Check'],
            'corporateConventions.provider.EDENRED' => ['Edenred', 'Edenred', 'Edenred', 'Edenred'],
            'corporateConventions.provider.PELLEGRINI' => ['Pellegrini', 'Pellegrini', 'Pellegrini', 'Pellegrini'],
            'corporateConventions.provider.PLUXEE_SODEXO' => ['Pluxee (Sodexo)', 'Pluxee (Sodexo)', 'Pluxee (Sodexo)', 'Pluxee (Sodexo)'],
            'corporateConventions.provider.EUDAIMON' => ['Eudaimon', 'Eudaimon', 'Eudaimon', 'Eudaimon'],
            'corporateConventions.provider.DOUBLEYOU' => ['DoubleYou', 'DoubleYou', 'DoubleYou', 'DoubleYou'],
            'corporateConventions.provider.REKA_CARD' => ['Reka', 'Reka', 'Reka', 'Reka'],
            'corporateConventions.provider.LUNCH_CHECK' => ['Lunch-Check', 'Lunch-Check', 'Lunch-Check', 'Lunch-Check'],
            'corporateConventions.provider.BONCADEAU' => ['Boncadeau', 'Boncadeau', 'Boncadeau', 'Boncadeau'],
            'corporateConventions.provider.OTHER' => ['Altro emittente', 'Other provider', 'Anderer Anbieter', 'Autre émetteur'],
            'corporateConventions.welfareCategory.EDUCATION' => ['Istruzione', 'Education', 'Bildung', 'Éducation'],
            'corporateConventions.welfareCategory.HEALTHCARE' => ['Salute', 'Healthcare', 'Gesundheit', 'Santé'],
            'corporateConventions.welfareCategory.SPORTS_FITNESS' => ['Sport e fitness', 'Sports and fitness', 'Sport und Fitness', 'Sport et fitness'],
            'corporateConventions.welfareCategory.CULTURE_LEISURE' => ['Cultura e tempo libero', 'Culture and leisure', 'Kultur und Freizeit', 'Culture et loisirs'],
            'corporateConventions.welfareCategory.SHOPPING_VOUCHER_EXEMPT' => ['Buono acquisto esente', 'Exempt shopping voucher', 'Steuerfreier Einkaufsgutschein', 'Bon d\'achat exonéré'],
            'corporateConventions.welfareCategory.MEAL_VOUCHER' => ['Buono pasto', 'Meal voucher', 'Essensgutschein', 'Titre-repas'],
            'corporateConventions.reimbursement.PENDING_SETTLEMENT' => ['Da regolare', 'Pending settlement', 'Abrechnung offen', 'En attente de règlement'],
            'corporateConventions.reimbursement.SUBMITTED_TO_PROVIDER' => ['Inviato all\'emittente', 'Submitted to provider', 'Beim Anbieter eingereicht', 'Soumis à l\'émetteur'],
            'corporateConventions.reimbursement.RECONCILED' => ['Riconciliato', 'Reconciled', 'Abgestimmt', 'Rapproché'],
            'corporateConventions.reimbursement.REJECTED' => ['Respinto', 'Rejected', 'Abgelehnt', 'Rejeté'],
        ];
    }

    /**
     * The refusals of the API, from the backend dictionary of the same module (`:name` placeholders become `{name}`).
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function errors(): array
    {
        $dictionaries = array_map(
            static fn (string $locale): array => (array) require lang_path($locale.'/corporate_conventions.php'),
            self::LOCALES,
        );
        $result = [
            'corporateConventions.error.generic' => ['Operazione non riuscita. Riprova.', 'The operation failed. Try again.', 'Der Vorgang ist fehlgeschlagen. Versuchen Sie es erneut.', 'L\'opération a échoué. Réessayez.'],
        ];

        foreach (array_keys($dictionaries[1]['errors']) as $code) {
            $texts = array_map(static fn (array $dictionary): string => (string) preg_replace('/:(\w+)/', '{$1}', (string) $dictionary['errors'][$code]), $dictionaries);
            $result['corporateConventions.error.'.$code] = [$texts[0], $texts[1], $texts[2], $texts[3]];
        }

        return $result;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function fields(): array
    {
        return [
            'corporateConventions.field.search' => ['Cerca', 'Search', 'Suchen', 'Rechercher'],
            'corporateConventions.field.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'corporateConventions.field.country' => ['Paese', 'Country', 'Land', 'Pays'],
            'corporateConventions.field.currency' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'corporateConventions.field.period' => ['Periodo', 'Period', 'Zeitraum', 'Période'],
            'corporateConventions.field.month' => ['Mese', 'Month', 'Monat', 'Mois'],
            'corporateConventions.field.code' => ['Codice', 'Code', 'Code', 'Code'],
            'corporateConventions.field.name' => ['Nome della convenzione', 'Convention name', 'Name der Konvention', 'Nom de la convention'],
            'corporateConventions.field.partnerCompanyName' => ['Ragione sociale del partner', 'Partner company name', 'Firmenname des Partners', 'Raison sociale du partenaire'],
            'corporateConventions.field.partnerType' => ['Tipo di partner', 'Partner type', 'Partnerart', 'Type de partenaire'],
            'corporateConventions.field.vatNumber' => ['P/IVA del partner', 'Partner P/IVA', 'MwSt-Nummer des Partners', 'P/IVA du partenaire'],
            'corporateConventions.field.uidNumber' => ['Numero UID del partner', 'Partner UID number', 'UID-Nummer des Partners', 'Numéro IDE du partenaire'],
            'corporateConventions.field.validFrom' => ['Valida dal', 'Valid from', 'Gültig ab', 'Valable dès'],
            'corporateConventions.field.validTo' => ['Valida fino al', 'Valid until', 'Gültig bis', 'Valable jusqu\'au'],
            'corporateConventions.field.eligiblePopulation' => ['Platea di dipendenti', 'Eligible population', 'Anspruchsberechtigte', 'Population éligible'],
            'corporateConventions.field.hrContactName' => ['Referente HR', 'HR contact', 'HR-Kontaktperson', 'Contact RH'],
            'corporateConventions.field.hrContactEmail' => ['E-mail del referente HR', 'HR contact e-mail', 'E-Mail der HR-Kontaktperson', 'E-mail du contact RH'],
            'corporateConventions.field.hrContactPhone' => ['Telefono del referente HR', 'HR contact phone', 'Telefon der HR-Kontaktperson', 'Téléphone du contact RH'],
            'corporateConventions.field.slug' => ['Indirizzo della pagina (slug)', 'Page address (slug)', 'Seitenadresse (Slug)', 'Adresse de la page (slug)'],
            'corporateConventions.field.primaryColor' => ['Colore principale', 'Primary colour', 'Hauptfarbe', 'Couleur principale'],
            'corporateConventions.field.welcomeMessageKey' => ['Chiave del messaggio di benvenuto', 'Welcome message key', 'Schlüssel der Willkommensnachricht', 'Clé du message de bienvenue'],
            'corporateConventions.field.contract' => ['Contratto (PDF)', 'Contract (PDF)', 'Vertrag (PDF)', 'Contrat (PDF)'],
            'corporateConventions.field.logo' => ['Logo della pagina', 'Page logo', 'Logo der Seite', 'Logo de la page'],
            'corporateConventions.field.verificationMethods' => ['Metodi di verifica', 'Verification methods', 'Prüfmethoden', 'Méthodes de vérification'],
            'corporateConventions.field.emailDomains' => ['Domini e-mail aziendali', 'Work e-mail domains', 'Firmen-E-Mail-Domains', 'Domaines e-mail professionnels'],
            'corporateConventions.field.cardPrefix' => ['Prefisso del numero tessera', 'Card number prefix', 'Präfix der Kartennummer', 'Préfixe du numéro de carte'],
            'corporateConventions.field.cardRegex' => ['Formato del numero tessera (espressione regolare)', 'Card number format (regular expression)', 'Format der Kartennummer (regulärer Ausdruck)', 'Format du numéro de carte (expression régulière)'],
            'corporateConventions.field.promoCode' => ['Codice convenzione', 'Convention code', 'Konventionscode', 'Code de convention'],
            'corporateConventions.field.allowFamily' => ['Ammetti i familiari di primo grado', 'Allow first-degree family members', 'Familienmitglieder ersten Grades zulassen', 'Admettre les membres de la famille du premier degré'],
            'corporateConventions.field.maxFamily' => ['Numero massimo di familiari', 'Maximum family members', 'Maximale Anzahl Familienmitglieder', 'Nombre maximal de membres de la famille'],
            'corporateConventions.field.relationship' => ['Grado di parentela', 'Relationship', 'Verwandtschaftsverhältnis', 'Lien de parenté'],
            'corporateConventions.field.annualCap' => ['Tetto di spesa annuo per beneficiario', 'Annual spending cap per beneficiary', 'Jahresausgabenlimit pro Begünstigtem', 'Plafond de dépenses annuel par bénéficiaire'],
            'corporateConventions.field.subsidyEnabled' => ['Contributo dell\'ente attivo', 'Employer subsidy enabled', 'Arbeitgeberzuschuss aktiv', 'Participation de l\'employeur active'],
            'corporateConventions.field.subsidyType' => ['Tipo di contributo', 'Subsidy type', 'Art des Zuschusses', 'Type de participation'],
            'corporateConventions.field.subsidyValue' => ['Valore del contributo (importo o %)', 'Subsidy value (amount or %)', 'Wert des Zuschusses (Betrag oder %)', 'Valeur de la participation (montant ou %)'],
            'corporateConventions.field.subsidyAnnualCap' => ['Tetto annuo del contributo', 'Annual subsidy cap', 'Jahreslimit des Zuschusses', 'Plafond annuel de la participation'],
            'corporateConventions.field.subsidyPurpose' => ['Finalità del contributo', 'Subsidy purpose', 'Zweck des Zuschusses', 'Finalité de la participation'],
            'corporateConventions.field.billingCycle' => ['Ciclo di fatturazione', 'Billing cycle', 'Abrechnungszyklus', 'Cycle de facturation'],
            'corporateConventions.field.anonymized' => ['Allegato beneficiari anonimizzato (GDPR / nLPD)', 'Anonymized beneficiary schedule (GDPR / nLPD)', 'Anonymisierte Begünstigtenliste (DSGVO / nDSG)', 'Annexe des bénéficiaires anonymisée (RGPD / nLPD)'],
            'corporateConventions.field.schedule' => ['Allegato beneficiari', 'Beneficiary schedule', 'Begünstigtenliste', 'Annexe des bénéficiaires'],
            'corporateConventions.field.jurisdiction' => ['Ordinamento', 'Jurisdiction', 'Rechtsordnung', 'Juridiction'],
            'corporateConventions.field.batchReference' => ['Riferimento del lotto', 'Batch reference', 'Losreferenz', 'Référence du lot'],
            'corporateConventions.field.selfInvoice' => ['Numero autofattura / distinta dell\'emittente', 'Provider self-invoice / statement number', 'Nummer der Eigenrechnung / Abrechnung des Anbieters', 'Numéro d\'autofacture / relevé de l\'émetteur'],
            'corporateConventions.field.statement' => ['Estratto di regolamento (una riga per buono: codice;importo)', 'Settlement statement (one line per voucher: code;amount)', 'Abrechnungsauszug (eine Zeile pro Gutschein: Code;Betrag)', 'Relevé de règlement (une ligne par bon : code;montant)'],
            'corporateConventions.placeholder.emailDomains' => ['es. @azienda.it', 'e.g. @company.com', 'z. B. @firma.ch', 'p. ex. @entreprise.ch'],
            'corporateConventions.placeholder.employeeList' => ['Incolla le matricole, una per riga', 'Paste the employee numbers, one per line', 'Personalnummern einfügen, eine pro Zeile', 'Collez les matricules, un par ligne'],
            'corporateConventions.placeholder.priceList' => ['Scegli un listino', 'Choose a price list', 'Preisliste wählen', 'Choisissez une liste de prix'],
            'corporateConventions.placeholder.category' => ['Scegli una categoria', 'Choose a category', 'Kategorie wählen', 'Choisissez une catégorie'],
            'corporateConventions.placeholder.statement' => ['ABC12345;9,20', 'ABC12345;9.20', 'ABC12345;9.20', 'ABC12345;9,20'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function columns(): array
    {
        return [
            'corporateConventions.column.convention' => ['Convenzione', 'Convention', 'Konvention', 'Convention'],
            'corporateConventions.column.partner' => ['Partner', 'Partner', 'Partner', 'Partenaire'],
            'corporateConventions.column.type' => ['Tipo', 'Type', 'Art', 'Type'],
            'corporateConventions.column.validity' => ['Validità', 'Validity', 'Gültigkeit', 'Validité'],
            'corporateConventions.column.enrolled' => ['Iscritti', 'Enrolled', 'Angemeldet', 'Inscrits'],
            'corporateConventions.column.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'corporateConventions.column.identifier' => ['Identificativo', 'Identifier', 'Kennung', 'Identifiant'],
            'corporateConventions.column.ruleType' => ['Tipo di regola', 'Rule type', 'Regeltyp', 'Type de règle'],
            'corporateConventions.column.target' => ['Bersaglio', 'Target', 'Ziel', 'Cible'],
            'corporateConventions.column.discount' => ['Sconto %', 'Discount %', 'Rabatt %', 'Remise %'],
            'corporateConventions.column.minCart' => ['Carrello minimo', 'Minimum cart', 'Mindestwarenkorb', 'Panier minimal'],
            'corporateConventions.column.excludePromo' => ['Escludi promozioni', 'Exclude promotions', 'Aktionen ausschließen', 'Exclure les promotions'],
            'corporateConventions.column.cumulative' => ['Cumulabile', 'Cumulative', 'Kumulierbar', 'Cumulable'],
            'corporateConventions.column.billingCycle' => ['Ciclo', 'Cycle', 'Zyklus', 'Cycle'],
            'corporateConventions.column.operations' => ['Operazioni', 'Operations', 'Vorgänge', 'Opérations'],
            'corporateConventions.column.employerQuota' => ['Quota ente', 'Employer quota', 'Arbeitgeberanteil', 'Quote-part employeur'],
            'corporateConventions.column.period' => ['Periodo', 'Period', 'Zeitraum', 'Période'],
            'corporateConventions.column.date' => ['Data', 'Date', 'Datum', 'Date'],
            'corporateConventions.column.beneficiary' => ['Beneficiario', 'Beneficiary', 'Begünstigte Person', 'Bénéficiaire'],
            'corporateConventions.column.document' => ['Documento', 'Document', 'Beleg', 'Document'],
            'corporateConventions.column.gross' => ['Lordo', 'Gross', 'Brutto', 'Brut'],
            'corporateConventions.column.employeePaid' => ['Quota dipendente', 'Employee share', 'Mitarbeiteranteil', 'Part de l\'employé'],
            'corporateConventions.column.provider' => ['Emittente', 'Provider', 'Anbieter', 'Émetteur'],
            'corporateConventions.column.vouchers' => ['Buoni', 'Vouchers', 'Gutscheine', 'Bons'],
            'corporateConventions.column.voucher' => ['Buono', 'Voucher', 'Gutschein', 'Bon'],
            'corporateConventions.column.category' => ['Categoria', 'Category', 'Kategorie', 'Catégorie'],
            'corporateConventions.column.merchantFee' => ['Commissione esercente', 'Merchant fee', 'Händlergebühr', 'Commission du commerçant'],
            'corporateConventions.column.netReceivable' => ['Netto da incassare', 'Net receivable', 'Netto-Forderung', 'Net à encaisser'],
            'corporateConventions.column.batch' => ['Lotto', 'Batch', 'Los', 'Lot'],
            'corporateConventions.column.expected' => ['Atteso', 'Expected', 'Erwartet', 'Attendu'],
            'corporateConventions.column.settled' => ['Regolato', 'Settled', 'Abgerechnet', 'Réglé'],
            'corporateConventions.column.difference' => ['Differenza', 'Difference', 'Differenz', 'Différence'],
            'corporateConventions.column.conversion' => ['Adesione', 'Take-up', 'Beteiligung', 'Adhésion'],
            'corporateConventions.column.transactions' => ['Acquisti', 'Purchases', 'Käufe', 'Achats'],
            'corporateConventions.column.grossRevenue' => ['Ricavi lordi', 'Gross revenue', 'Bruttoumsatz', 'Chiffre d\'affaires brut'],
            'corporateConventions.column.employerBilled' => ['Quota ente fatturata', 'Employer quota billed', 'Fakturierter Arbeitgeberanteil', 'Quote-part employeur facturée'],
            'corporateConventions.column.employerPending' => ['Quota ente da fatturare', 'Employer quota to bill', 'Zu fakturierender Arbeitgeberanteil', 'Quote-part employeur à facturer'],
            'corporateConventions.column.welfareFees' => ['Commissioni welfare', 'Welfare fees', 'Welfare-Gebühren', 'Commissions welfare'],
            'corporateConventions.column.netMargin' => ['Margine netto', 'Net margin', 'Nettomarge', 'Marge nette'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function actions(): array
    {
        return [
            'corporateConventions.action.newConvention' => ['Nuova convenzione', 'New convention', 'Neue Konvention', 'Nouvelle convention'],
            'corporateConventions.action.edit' => ['Modifica', 'Edit', 'Bearbeiten', 'Modifier'],
            'corporateConventions.action.delete' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'corporateConventions.action.activate' => ['Attiva', 'Activate', 'Aktivieren', 'Activer'],
            'corporateConventions.action.suspend' => ['Sospendi', 'Suspend', 'Aussetzen', 'Suspendre'],
            'corporateConventions.action.expire' => ['Chiudi', 'Close', 'Beenden', 'Clôturer'],
            'corporateConventions.action.landing' => ['Pagina pubblica', 'Public page', 'Öffentliche Seite', 'Page publique'],
            'corporateConventions.action.contract' => ['Contratto', 'Contract', 'Vertrag', 'Contrat'],
            'corporateConventions.action.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'corporateConventions.action.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'corporateConventions.action.confirm' => ['Conferma', 'Confirm', 'Bestätigen', 'Confirmer'],
            'corporateConventions.action.addRule' => ['Aggiungi regola', 'Add rule', 'Regel hinzufügen', 'Ajouter une règle'],
            'corporateConventions.action.removeRule' => ['Rimuovi regola', 'Remove rule', 'Regel entfernen', 'Supprimer la règle'],
            'corporateConventions.action.moveUp' => ['Sposta su (priorità maggiore)', 'Move up (higher priority)', 'Nach oben (höhere Priorität)', 'Monter (priorité supérieure)'],
            'corporateConventions.action.moveDown' => ['Sposta giù (priorità minore)', 'Move down (lower priority)', 'Nach unten (niedrigere Priorität)', 'Descendre (priorité inférieure)'],
            'corporateConventions.action.previewHashes' => ['Anteprima hash SHA-256', 'Preview SHA-256 hashes', 'SHA-256-Hashes ansehen', 'Aperçu des hachages SHA-256'],
            'corporateConventions.action.importList' => ['Importa elenco', 'Import list', 'Liste importieren', 'Importer la liste'],
            'corporateConventions.action.exportCsv' => ['Esporta CSV / Excel', 'Export CSV / Excel', 'CSV / Excel exportieren', 'Exporter CSV / Excel'],
            'corporateConventions.action.preview' => ['Anteprima', 'Preview', 'Vorschau', 'Aperçu'],
            'corporateConventions.action.generateInvoice' => ['Genera fattura consolidata', 'Generate consolidated invoice', 'Sammelrechnung erstellen', 'Générer la facture consolidée'],
            'corporateConventions.action.downloadSchedule' => ['Scarica allegato beneficiari', 'Download beneficiary schedule', 'Begünstigtenliste herunterladen', 'Télécharger l\'annexe des bénéficiaires'],
            'corporateConventions.action.select' => ['Seleziona', 'Select', 'Auswählen', 'Sélectionner'],
            'corporateConventions.action.submitBatch' => ['Invia lotto all\'emittente', 'Submit batch to provider', 'Los beim Anbieter einreichen', 'Soumettre le lot à l\'émetteur'],
            'corporateConventions.action.reconcile' => ['Riconcilia con l\'estratto', 'Reconcile with the statement', 'Mit dem Auszug abstimmen', 'Rapprocher avec le relevé'],
            'corporateConventions.action.verify' => ['Verifica', 'Verify', 'Prüfen', 'Vérifier'],
            'corporateConventions.confirm.delete' => ['Eliminare la convenzione?', 'Delete the convention?', 'Konvention löschen?', 'Supprimer la convention ?'],
            'corporateConventions.confirm.expire' => ['Chiudere la convenzione? Non potrà essere riaperta.', 'Close the convention? It cannot be reopened.', 'Konvention beenden? Sie kann nicht wieder geöffnet werden.', 'Clôturer la convention ? Elle ne pourra pas être rouverte.'],
            'corporateConventions.confirm.generateInvoice' => ['Generare la fattura consolidata della quota ente per il periodo?', 'Generate the consolidated invoice of the employer quota for the period?', 'Die Sammelrechnung des Arbeitgeberanteils für den Zeitraum erstellen?', 'Générer la facture consolidée de la quote-part employeur pour la période ?'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function sections(): array
    {
        return [
            'corporateConventions.section.convention' => ['Convenzione', 'Convention', 'Konvention', 'Convention'],
            'corporateConventions.section.hrContact' => ['Referente HR del partner', 'Partner HR contact', 'HR-Kontakt des Partners', 'Contact RH du partenaire'],
            'corporateConventions.section.landingPage' => ['Pagina di adesione', 'Sign-up page', 'Anmeldeseite', 'Page d\'adhésion'],
            'corporateConventions.section.employeeList' => ['Elenco dipendenti (SHA-256)', 'Employee list (SHA-256)', 'Mitarbeiterliste (SHA-256)', 'Liste des employés (SHA-256)'],
            'corporateConventions.section.family' => ['Familiari di primo grado', 'First-degree family members', 'Familienmitglieder ersten Grades', 'Membres de la famille du premier degré'],
            'corporateConventions.section.roiByConvention' => ['Ricavi e margine per convenzione', 'Revenue and margin by convention', 'Umsatz und Marge je Konvention', 'Chiffre d\'affaires et marge par convention'],
            'corporateConventions.section.settlement' => ['Regolamento con l\'emittente', 'Settlement with the provider', 'Abrechnung mit dem Anbieter', 'Règlement avec l\'émetteur'],
            'corporateConventions.tab.general' => ['Partner e dati generali', 'Partner and general data', 'Partner und allgemeine Daten', 'Partenaire et données générales'],
            'corporateConventions.tab.eligibility' => ['Idoneità e verifica', 'Eligibility and verification', 'Berechtigung und Prüfung', 'Éligibilité et vérification'],
            'corporateConventions.tab.matrix' => ['Matrice sconti e listini', 'Discount matrix and price lists', 'Rabattmatrix und Preislisten', 'Matrice de remises et listes de prix'],
            'corporateConventions.tab.billing' => ['Split billing e contributo ente', 'Split billing and employer subsidy', 'Split Billing und Arbeitgeberzuschuss', 'Split billing et participation employeur'],
            'corporateConventions.tab.splitBilling' => ['Quote ente da fatturare', 'Employer quotas to invoice', 'Zu fakturierende Arbeitgeberanteile', 'Quotes-parts employeur à facturer'],
            'corporateConventions.tab.welfare' => ['Buoni welfare', 'Welfare vouchers', 'Welfare-Gutscheine', 'Bons welfare'],
            'corporateConventions.empty.conventions' => ['Nessuna convenzione', 'No conventions', 'Keine Konventionen', 'Aucune convention'],
            'corporateConventions.empty.roi' => ['Nessun dato per i filtri scelti', 'No data for the chosen filters', 'Keine Daten für die gewählten Filter', 'Aucune donnée pour les filtres choisis'],
            'corporateConventions.empty.pendingQuotas' => ['Nessuna quota ente in attesa di fatturazione', 'No employer quota waiting to be invoiced', 'Kein Arbeitgeberanteil wartet auf Fakturierung', 'Aucune quote-part employeur en attente de facturation'],
            'corporateConventions.empty.vouchers' => ['Nessun buono welfare', 'No welfare vouchers', 'Keine Welfare-Gutscheine', 'Aucun bon welfare'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function hints(): array
    {
        return [
            'corporateConventions.hint.slug' => ['Lettere minuscole, numeri e trattini: la pagina sarà /convenzioni/<slug>. Lascia vuoto per generarlo dal nome.', 'Lower case letters, digits and dashes: the page will be /convenzioni/<slug>. Leave empty to generate it from the name.', 'Kleinbuchstaben, Ziffern und Bindestriche: Die Seite lautet /convenzioni/<slug>. Leer lassen, um ihn aus dem Namen zu erzeugen.', 'Minuscules, chiffres et tirets : la page sera /convenzioni/<slug>. Laissez vide pour le générer à partir du nom.'],
            'corporateConventions.hint.welcomeMessageKey' => ['Facoltativo: chiave di un testo di benvenuto personalizzato.', 'Optional: key of a custom welcome text.', 'Optional: Schlüssel eines eigenen Willkommenstexts.', 'Facultatif : clé d\'un texte de bienvenue personnalisé.'],
            'corporateConventions.hint.filesAfterSave' => ['Salva la convenzione per caricare contratto, logo ed elenco dipendenti.', 'Save the convention to upload the contract, the logo and the employee list.', 'Speichern Sie die Konvention, um Vertrag, Logo und Mitarbeiterliste hochzuladen.', 'Enregistrez la convention pour téléverser le contrat, le logo et la liste des employés.'],
            'corporateConventions.hint.invalidDomains' => ['Domini non validi', 'Invalid domains', 'Ungültige Domains', 'Domaines invalides'],
            'corporateConventions.hint.employeeList' => ['Le matricole non vengono salvate: restano solo i loro hash SHA-256 (GDPR / nLPD). Il file CSV usa la prima colonna.', 'The employee numbers are not stored: only their SHA-256 hashes remain (GDPR / nLPD). A CSV file uses its first column.', 'Die Personalnummern werden nicht gespeichert: Es bleiben nur ihre SHA-256-Hashes (DSGVO / nDSG). Eine CSV-Datei verwendet die erste Spalte.', 'Les matricules ne sont pas conservés : seuls leurs hachages SHA-256 restent (RGPD / nLPD). Un fichier CSV utilise sa première colonne.'],
            'corporateConventions.hint.matrix' => ['Le regole si applicano in ordine di priorità. Una regola non cumulabile sostituisce lo sconto già presente solo se è più conveniente e chiude la catena.', 'Rules apply in priority order. A non-cumulative rule replaces the discount already on the line only when it is better, and ends the chain.', 'Die Regeln gelten in Prioritätsreihenfolge. Eine nicht kumulierbare Regel ersetzt den bereits vorhandenen Rabatt nur, wenn sie besser ist, und beendet die Kette.', 'Les règles s\'appliquent par ordre de priorité. Une règle non cumulable remplace la remise déjà présente seulement si elle est meilleure et met fin à la chaîne.'],
            'corporateConventions.hint.annualCap' => ['Limita la spesa scontata di ogni beneficiario nell\'anno: oltre il tetto lo sconto non si applica.', 'Limits the discounted spend of each beneficiary in the year: beyond the cap the discount no longer applies.', 'Begrenzt die rabattierten Ausgaben jeder begünstigten Person im Jahr: Über dem Limit gilt der Rabatt nicht mehr.', 'Limite les dépenses remisées de chaque bénéficiaire dans l\'année : au-delà du plafond la remise ne s\'applique plus.'],
            'corporateConventions.hint.anonymized' => ['Con l\'anonimizzazione l\'allegato della fattura mostra solo pseudonimi dei beneficiari.', 'With anonymization the invoice attachment shows only pseudonyms of the beneficiaries.', 'Bei Anonymisierung zeigt der Rechnungsanhang nur Pseudonyme der Begünstigten.', 'Avec l\'anonymisation, l\'annexe de la facture n\'affiche que des pseudonymes des bénéficiaires.'],
            'corporateConventions.hint.invalidStatementLines' => ['Righe non valide', 'Invalid lines', 'Ungültige Zeilen', 'Lignes invalides'],
            'corporateConventions.label.uploaded' => ['Caricato', 'Uploaded', 'Hochgeladen', 'Téléversé'],
            'corporateConventions.label.listSize' => ['Righe in elenco', 'Entries on the list', 'Einträge in der Liste', 'Entrées dans la liste'],
            'corporateConventions.label.listUsed' => ['Già utilizzate', 'Already used', 'Bereits verwendet', 'Déjà utilisées'],
            'corporateConventions.label.importResult' => ['Ricevuti {received}: importati {imported}, duplicati {duplicates}, non validi {invalid}', 'Received {received}: imported {imported}, duplicates {duplicates}, invalid {invalid}', 'Empfangen {received}: importiert {imported}, Duplikate {duplicates}, ungültig {invalid}', 'Reçus {received} : importés {imported}, doublons {duplicates}, invalides {invalid}'],
            'corporateConventions.label.allProducts' => ['Tutti i prodotti', 'All products', 'Alle Produkte', 'Tous les produits'],
            'corporateConventions.label.family' => ['familiari', 'family members', 'Familienmitglieder', 'membres de la famille'],
            'corporateConventions.label.scheduleDefault' => ['Come da convenzione', 'As set by the convention', 'Wie in der Konvention', 'Selon la convention'],
            'corporateConventions.label.scheduleNominal' => ['Nominativo', 'Nominal', 'Namentlich', 'Nominatif'],
            'corporateConventions.label.scheduleAnonymized' => ['Anonimizzato', 'Anonymized', 'Anonymisiert', 'Anonymisé'],
            'corporateConventions.label.invoiceCreated' => ['Fattura {number} creata per {rows} operazioni', 'Invoice {number} created for {rows} operations', 'Rechnung {number} für {rows} Vorgänge erstellt', 'Facture {number} créée pour {rows} opérations'],
            'corporateConventions.label.reconciliationResult' => ['Riconciliati {reconciled}, respinti {rejected}', 'Reconciled {reconciled}, rejected {rejected}', 'Abgestimmt {reconciled}, abgelehnt {rejected}', 'Rapprochés {reconciled}, rejetés {rejected}'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function dashboard(): array
    {
        return [
            'corporateConventions.kpi.activeConventions' => ['Convenzioni attive', 'Active conventions', 'Aktive Konventionen', 'Conventions actives'],
            'corporateConventions.kpi.enrolledEmployees' => ['Dipendenti iscritti', 'Enrolled employees', 'Angemeldete Mitarbeitende', 'Employés inscrits'],
            'corporateConventions.kpi.grossRevenue' => ['Ricavi lordi generati', 'Gross revenue generated', 'Generierter Bruttoumsatz', 'Chiffre d\'affaires brut généré'],
            'corporateConventions.kpi.netRevenue' => ['Ricavi netti di sconto', 'Revenue net of discount', 'Umsatz nach Rabatt', 'Chiffre d\'affaires net de remise'],
            'corporateConventions.kpi.averageDiscount' => ['Sconto medio', 'Average discount', 'Durchschnittlicher Rabatt', 'Remise moyenne'],
            'corporateConventions.kpi.employerBilled' => ['Quota ente fatturata', 'Employer quota billed', 'Fakturierter Arbeitgeberanteil', 'Quote-part employeur facturée'],
            'corporateConventions.kpi.netMargin' => ['Margine netto', 'Net margin', 'Nettomarge', 'Marge nette'],
            'corporateConventions.kpi.pendingSplitBilling' => ['Split billing da fatturare', 'Split billing to invoice', 'Zu fakturierendes Split Billing', 'Split billing à facturer'],
            'corporateConventions.kpi.pendingWelfare' => ['Buoni welfare da riconciliare', 'Welfare vouchers to reconcile', 'Abzustimmende Welfare-Gutscheine', 'Bons welfare à rapprocher'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function till(): array
    {
        return [
            'corporateConventions.pos.title' => ['Convenzione e welfare', 'Convention and welfare', 'Konvention und Welfare', 'Convention et welfare'],
            'corporateConventions.pos.identifier' => ['Tessera, e-mail, numero tessera o matricola', 'Pass, e-mail, card number or employee number', 'Pass, E-Mail, Kartennummer oder Personalnummer', 'Carte, e-mail, numéro de carte ou matricule'],
            'corporateConventions.pos.identifierPlaceholder' => ['Scansiona il QR o digita il codice', 'Scan the QR code or type the code', 'QR-Code scannen oder Code eingeben', 'Scannez le QR code ou saisissez le code'],
            'corporateConventions.pos.capRemaining' => ['Tetto annuo residuo', 'Annual cap remaining', 'Verbleibendes Jahreslimit', 'Plafond annuel restant'],
            'corporateConventions.pos.subsidyRemaining' => ['Contributo ente residuo', 'Employer subsidy remaining', 'Verbleibender Arbeitgeberzuschuss', 'Participation employeur restante'],
            'corporateConventions.pos.calculating' => ['Calcolo in corso…', 'Calculating…', 'Berechnung läuft…', 'Calcul en cours…'],
            'corporateConventions.pos.total' => ['Totale', 'Total', 'Total', 'Total'],
            'corporateConventions.pos.conventionDiscount' => ['Sconto convenzione', 'Convention discount', 'Konventionsrabatt', 'Remise convention'],
            'corporateConventions.pos.employerQuota' => ['Quota a carico dell\'ente', 'Employer quota', 'Arbeitgeberanteil', 'Quote-part de l\'employeur'],
            'corporateConventions.pos.employeePayable' => ['Da pagare dal dipendente', 'Employee payable', 'Vom Mitarbeitenden zu zahlen', 'À payer par l\'employé'],
            'corporateConventions.pos.capReached' => ['Tetto annuo raggiunto: lo sconto è limitato alla parte residua.', 'Annual cap reached: the discount is limited to what is left.', 'Jahreslimit erreicht: Der Rabatt ist auf den Rest begrenzt.', 'Plafond annuel atteint : la remise est limitée au solde restant.'],
            'corporateConventions.pos.apply' => ['Applica la convenzione', 'Apply the convention', 'Konvention anwenden', 'Appliquer la convention'],
            'corporateConventions.pos.applied' => ['Convenzione applicata: ripartizione registrata.', 'Convention applied: split recorded.', 'Konvention angewendet: Aufteilung erfasst.', 'Convention appliquée : répartition enregistrée.'],
            'corporateConventions.pos.welfareTitle' => ['Pagamento con buono welfare', 'Payment with a welfare voucher', 'Zahlung mit Welfare-Gutschein', 'Paiement avec un bon welfare'],
            'corporateConventions.pos.voucherCode' => ['Codice o barcode del buono', 'Voucher code or barcode', 'Gutscheincode oder Barcode', 'Code ou code-barres du bon'],
            'corporateConventions.pos.voucherAmount' => ['Valore del buono', 'Voucher value', 'Gutscheinwert', 'Valeur du bon'],
            'corporateConventions.pos.feePercentage' => ['Commissione %', 'Fee %', 'Gebühr %', 'Commission %'],
            'corporateConventions.pos.merchantFee' => ['Commissione', 'Fee', 'Gebühr', 'Commission'],
            'corporateConventions.pos.netReceivable' => ['Netto da incassare', 'Net receivable', 'Netto-Forderung', 'Net à encaisser'],
            'corporateConventions.pos.redeem' => ['Incassa buono', 'Redeem voucher', 'Gutschein einlösen', 'Encaisser le bon'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function landing(): array
    {
        return [
            'corporateConventions.landing.title' => ['Convenzione dipendenti', 'Employee convention', 'Mitarbeiterkonvention', 'Convention employés'],
            'corporateConventions.landing.welcome' => ['Benvenuto! Verifica in pochi secondi di essere un dipendente di {partner} e ottieni la tua tessera sconto.', 'Welcome! Verify in a few seconds that you work at {partner} and get your discount pass.', 'Willkommen! Bestätigen Sie in wenigen Sekunden, dass Sie bei {partner} arbeiten, und erhalten Sie Ihren Rabattpass.', 'Bienvenue ! Vérifiez en quelques secondes que vous travaillez chez {partner} et obtenez votre carte de remise.'],
            'corporateConventions.landing.unavailableTitle' => ['Convenzione non disponibile', 'Convention not available', 'Konvention nicht verfügbar', 'Convention indisponible'],
            'corporateConventions.landing.unavailableText' => ['Questo link non è più valido o la convenzione non è attiva. Chiedi al tuo referente HR.', 'This link is no longer valid or the convention is not active. Ask your HR contact.', 'Dieser Link ist nicht mehr gültig oder die Konvention ist nicht aktiv. Fragen Sie Ihre HR-Kontaktperson.', 'Ce lien n\'est plus valide ou la convention n\'est pas active. Demandez à votre contact RH.'],
            'corporateConventions.landing.fullName' => ['Nome e cognome', 'Full name', 'Vor- und Nachname', 'Nom et prénom'],
            'corporateConventions.landing.workEmail' => ['E-mail aziendale', 'Work e-mail', 'Firmen-E-Mail', 'E-mail professionnelle'],
            'corporateConventions.landing.sendCode' => ['Invia il codice', 'Send the code', 'Code senden', 'Envoyer le code'],
            'corporateConventions.landing.or' => ['oppure', 'or', 'oder', 'ou'],
            'corporateConventions.landing.cardNumber' => ['Numero tessera', 'Card number', 'Kartennummer', 'Numéro de carte'],
            'corporateConventions.landing.matricola' => ['Matricola o badge', 'Employee or badge number', 'Personal- oder Badge-Nummer', 'Matricule ou badge'],
            'corporateConventions.landing.promoCode' => ['Codice convenzione', 'Convention code', 'Konventionscode', 'Code de convention'],
            'corporateConventions.landing.verifyIdentifier' => ['Verifica', 'Verify', 'Prüfen', 'Vérifier'],
            'corporateConventions.landing.privacy' => ['Usiamo i dati solo per verificare la convenzione. La tua matricola non viene mai salvata in chiaro.', 'We use your data only to verify the convention. Your employee number is never stored in clear.', 'Wir verwenden Ihre Daten nur zur Prüfung der Konvention. Ihre Personalnummer wird nie im Klartext gespeichert.', 'Nous utilisons vos données uniquement pour vérifier la convention. Votre matricule n\'est jamais conservé en clair.'],
            'corporateConventions.landing.codeSent' => ['Abbiamo inviato un codice a {email}.', 'We sent a code to {email}.', 'Wir haben einen Code an {email} gesendet.', 'Nous avons envoyé un code à {email}.'],
            'corporateConventions.landing.code' => ['Codice a 6 cifre', '6-digit code', '6-stelliger Code', 'Code à 6 chiffres'],
            'corporateConventions.landing.activate' => ['Attiva la tessera', 'Activate the pass', 'Pass aktivieren', 'Activer la carte'],
            'corporateConventions.landing.back' => ['Indietro', 'Back', 'Zurück', 'Retour'],
            'corporateConventions.landing.qrAlt' => ['QR code della tessera convenzione', 'QR code of the convention pass', 'QR-Code des Konventionspasses', 'QR code de la carte de convention'],
            'corporateConventions.landing.validUntil' => ['Valida fino al', 'Valid until', 'Gültig bis', 'Valable jusqu\'au'],
            'corporateConventions.landing.addApple' => ['Aggiungi ad Apple Wallet', 'Add to Apple Wallet', 'Zu Apple Wallet hinzufügen', 'Ajouter à Apple Wallet'],
            'corporateConventions.landing.addGoogle' => ['Aggiungi a Google Wallet', 'Add to Google Wallet', 'Zu Google Wallet hinzufügen', 'Ajouter à Google Wallet'],
            'corporateConventions.landing.downloadQr' => ['Scarica il QR code', 'Download the QR code', 'QR-Code herunterladen', 'Télécharger le QR code'],
            'corporateConventions.landing.familyTitle' => ['Aggiungi i tuoi familiari', 'Add your family members', 'Familienmitglieder hinzufügen', 'Ajoutez vos proches'],
            'corporateConventions.landing.addFamily' => ['Aggiungi familiare', 'Add family member', 'Familienmitglied hinzufügen', 'Ajouter un proche'],
            'corporateConventions.landing.familyLimit' => ['Hai raggiunto il numero massimo di familiari ({max}).', 'You have reached the maximum number of family members ({max}).', 'Sie haben die maximale Anzahl Familienmitglieder ({max}) erreicht.', 'Vous avez atteint le nombre maximal de proches ({max}).'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function messages(): array
    {
        return [
            'corporateConventions.notification.saved' => ['Convenzione salvata', 'Convention saved', 'Konvention gespeichert', 'Convention enregistrée'],
            'corporateConventions.notification.deleted' => ['Convenzione eliminata', 'Convention deleted', 'Konvention gelöscht', 'Convention supprimée'],
            'corporateConventions.notification.statusChanged' => ['Stato aggiornato', 'Status updated', 'Status aktualisiert', 'Statut mis à jour'],
            'corporateConventions.notification.listImported' => ['Elenco importato', 'List imported', 'Liste importiert', 'Liste importée'],
            'corporateConventions.notification.fileUploaded' => ['File caricato', 'File uploaded', 'Datei hochgeladen', 'Fichier téléversé'],
            'corporateConventions.notification.exported' => ['Esportazione completata', 'Export completed', 'Export abgeschlossen', 'Export terminé'],
            'corporateConventions.notification.invoiceCreated' => ['Fattura consolidata creata', 'Consolidated invoice created', 'Sammelrechnung erstellt', 'Facture consolidée créée'],
            'corporateConventions.notification.batchSubmitted' => ['Lotto inviato', 'Batch submitted', 'Los eingereicht', 'Lot soumis'],
            'corporateConventions.notification.reconciled' => ['Riconciliazione completata', 'Reconciliation completed', 'Abstimmung abgeschlossen', 'Rapprochement terminé'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function all(string $locale): array
    {
        $index = match ($locale) {
            'it' => 0,
            'en' => 1,
            'de' => 2,
            'fr' => 3,
            default => throw new InvalidArgumentException("Unsupported locale [{$locale}]."),
        };

        $resolved = [];

        foreach (self::definitions() as $key => $tuple) {
            $resolved[$key] = $tuple[$index];
        }

        return $resolved;
    }
}
