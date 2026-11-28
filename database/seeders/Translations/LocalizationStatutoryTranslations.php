<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * Locked statutory fiscal texts (CH MWST/TVA/IVA, QR-Bill SIX v2.3, nLPD, IT natura N1-N7, Art. 10/17/17-ter/21 DPR 633/72).
 * Rows are seeded with is_locked = true: tenants cannot override them. Legal acronyms (IDI, UID, IDE, QR) stay untranslated.
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 */
final class LocalizationStatutoryTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            // Switzerland: VAT number suffix, UID, flat-rate settlement, nLPD
            'statutory.ch.vat_suffix' => ['IVA', 'VAT', 'MWST', 'TVA'],
            'statutory.ch.uid_label' => ['IDI', 'UID', 'UID', 'IDE'],
            'statutory.ch.flat_rate_notice' => ['Conteggio secondo l’aliquota saldo', 'Settled at the flat tax rate (Saldosteuersatz)', 'Abrechnung nach Saldosteuersatz', 'Décompte selon le taux de la dette fiscale nette'],
            'statutory.ch.nlpd_notice' => [
                'Informativa ai sensi della nuova legge federale sulla protezione dei dati (nLPD): i dati personali sono trattati esclusivamente per l’esecuzione del contratto e gli obblighi di legge.',
                'Notice under the revised Swiss Federal Act on Data Protection (nFADP): personal data is processed solely to perform the contract and to meet legal obligations.',
                'Information gemäss dem neuen Bundesgesetz über den Datenschutz (nDSG): Personendaten werden ausschliesslich zur Vertragserfüllung und zur Erfüllung gesetzlicher Pflichten bearbeitet.',
                'Information selon la nouvelle loi fédérale sur la protection des données (nLPD) : les données personnelles sont traitées exclusivement pour l’exécution du contrat et le respect des obligations légales.',
            ],

            // QR-bill, SIX Swiss Payment Standards v2.3 section headings
            'statutory.ch.qr_bill.receipt' => ['Ricevuta', 'Receipt', 'Empfangsschein', 'Récépissé'],
            'statutory.ch.qr_bill.payment_part' => ['Sezione di pagamento', 'Payment part', 'Zahlteil', 'Section paiement'],
            'statutory.ch.qr_bill.account_payable_to' => ['Conto / Pagabile a', 'Account / Payable to', 'Konto / Zahlbar an', 'Compte / Payable à'],
            'statutory.ch.qr_bill.reference' => ['Riferimento', 'Reference', 'Referenz', 'Référence'],
            'statutory.ch.qr_bill.additional_information' => ['Informazioni aggiuntive', 'Additional information', 'Zusätzliche Informationen', 'Informations supplémentaires'],
            'statutory.ch.qr_bill.payable_by' => ['Pagabile da', 'Payable by', 'Zahlbar durch', 'Payable par'],
            'statutory.ch.qr_bill.currency' => ['Valuta', 'Currency', 'Währung', 'Monnaie'],
            'statutory.ch.qr_bill.amount' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'statutory.ch.qr_bill.acceptance_point' => ['Punto di accettazione', 'Acceptance point', 'Annahmestelle', 'Point de dépôt'],

            // Italy: natura of VAT-free operations (FatturaPA N1 to N7)
            'statutory.it.natura.n1' => ['Operazione esclusa dalla base imponibile IVA ai sensi dell’art. 15 DPR 633/72', 'Operation excluded from the VAT taxable base under Art. 15 DPR 633/72', 'Vorgang ausserhalb der Mehrwertsteuer-Bemessungsgrundlage nach Art. 15 DPR 633/72', 'Opération exclue de la base imposable à la TVA selon l’art. 15 DPR 633/72'],
            'statutory.it.natura.n2' => ['Operazione non soggetta a IVA', 'Operation not subject to VAT', 'Nicht der Mehrwertsteuer unterliegender Vorgang', 'Opération non soumise à la TVA'],
            'statutory.it.natura.n3' => ['Operazione non imponibile ai fini IVA', 'Operation not taxable for VAT purposes', 'Nicht steuerbarer Vorgang im Sinne der Mehrwertsteuer', 'Opération non imposable à la TVA'],
            'statutory.it.natura.n4' => ['Operazione esente da IVA', 'VAT-exempt operation', 'Von der Mehrwertsteuer befreiter Vorgang', 'Opération exonérée de TVA'],
            'statutory.it.natura.n5' => ['Operazione soggetta al regime del margine, IVA non esposta in fattura', 'Operation under the margin scheme, VAT not shown on the invoice', 'Vorgang im Margenverfahren, Mehrwertsteuer nicht ausgewiesen', 'Opération soumise au régime de la marge, TVA non indiquée sur la facture'],
            'statutory.it.natura.n6' => ['Operazione in inversione contabile (reverse charge)', 'Reverse charge operation', 'Vorgang mit Umkehr der Steuerschuldnerschaft (Reverse Charge)', 'Opération en autoliquidation (reverse charge)'],
            'statutory.it.natura.n7' => ['IVA assolta in altro Stato UE', 'VAT paid in another EU member state', 'Mehrwertsteuer in einem anderen EU-Staat entrichtet', 'TVA acquittée dans un autre État de l’UE'],

            // Italy: statutory clauses
            'statutory.it.art10_dpr633' => ['Operazione esente da IVA ai sensi dell’art. 10 DPR 633/72', 'Operation exempt from VAT under Art. 10 DPR 633/72', 'Von der Mehrwertsteuer befreiter Vorgang nach Art. 10 DPR 633/72', 'Opération exonérée de TVA selon l’art. 10 DPR 633/72'],
            'statutory.it.split_payment' => ['Scissione dei pagamenti ai sensi dell’art. 17-ter DPR 633/72', 'Split payment under Art. 17-ter DPR 633/72', 'Spaltung der Zahlungen (Split Payment) nach Art. 17-ter DPR 633/72', 'Paiement scindé (split payment) selon l’art. 17-ter DPR 633/72'],
            'statutory.it.reverse_charge' => ['Inversione contabile ai sensi dell’art. 17 DPR 633/72', 'Reverse charge under Art. 17 DPR 633/72', 'Umkehr der Steuerschuldnerschaft nach Art. 17 DPR 633/72', 'Autoliquidation selon l’art. 17 DPR 633/72'],
            'statutory.it.virtual_stamp' => ['Imposta di bollo assolta in modo virtuale', 'Stamp duty paid virtually', 'Stempelsteuer auf virtuelle Weise entrichtet', 'Droit de timbre acquitté de manière virtuelle'],
            'statutory.it.gdpr_art12' => [
                'Informativa ai sensi dell’art. 12 e seguenti del Regolamento UE 2016/679 (GDPR): i dati personali sono trattati per la gestione del rapporto contrattuale e degli obblighi fiscali.',
                'Information under Art. 12 et seq. of Regulation (EU) 2016/679 (GDPR): personal data is processed to manage the contractual relationship and tax obligations.',
                'Information gemäss Art. 12 ff. der Verordnung (EU) 2016/679 (DSGVO): Personendaten werden zur Abwicklung des Vertragsverhältnisses und der steuerlichen Pflichten verarbeitet.',
                'Information selon l’art. 12 et suivants du règlement (UE) 2016/679 (RGPD) : les données personnelles sont traitées pour la gestion de la relation contractuelle et des obligations fiscales.',
            ],
            'statutory.it.translation_notice' => [
                'Traduzione italiana della fattura (art. 21 DPR 633/72): fa fede la fattura emessa.',
                'Italian translation of the invoice (Art. 21 DPR 633/72): the invoice as issued prevails.',
                'Italienische Übersetzung der Rechnung (Art. 21 DPR 633/72): massgebend ist die ausgestellte Rechnung.',
                'Traduction italienne de la facture (art. 21 DPR 633/72) : la facture émise fait foi.',
            ],
            'statutory.it.translation_title' => ['Traduzione italiana', 'Italian translation', 'Italienische Übersetzung', 'Traduction italienne'],
        ];
    }

    /**
     * The locked keys, for the seeder.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::definitions());
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
