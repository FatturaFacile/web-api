<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * Labels of the Swiss QR payment slip preview (receipt and payment part). The SIX Swiss Payment Standards fix the
 * wording per slip language, and the slip language is chosen independently of the UI language, so the SPA reads
 * these keys from the dictionary of the slip language.
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 */
final class SwissQrBillSlipFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'qrBill.slip.receipt' => ['Ricevuta', 'Receipt', 'Empfangsschein', 'Récépissé'],
            'qrBill.slip.paymentPart' => ['Sezione pagamento', 'Payment part', 'Zahlteil', 'Section paiement'],
            'qrBill.slip.accountPayableTo' => ['Conto / Pagabile a', 'Account / Payable to', 'Konto / Zahlbar an', 'Compte / Payable à'],
            'qrBill.slip.reference' => ['Riferimento', 'Reference', 'Referenz', 'Référence'],
            'qrBill.slip.additionalInformation' => ['Informazioni aggiuntive', 'Additional information', 'Zusätzliche Informationen', 'Informations supplémentaires'],
            'qrBill.slip.payableBy' => ['Pagabile da', 'Payable by', 'Zahlbar durch', 'Payable par'],
            'qrBill.slip.payableByName' => ['Pagabile da (nome/indirizzo)', 'Payable by (name/address)', 'Zahlbar durch (Name/Adresse)', 'Payable par (nom/adresse)'],
            'qrBill.slip.currency' => ['Valuta', 'Currency', 'Währung', 'Monnaie'],
            'qrBill.slip.amount' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'qrBill.slip.acceptancePoint' => ['Punto di accettazione', 'Acceptance point', 'Annahmestelle', 'Point de dépôt'],
            'qrBill.slip.separateBeforePaying' => ['Da staccare prima del versamento', 'Separate before paying in', 'Vor der Einzahlung abzutrennen', 'A détacher avant le versement'],
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
            default => 1,
        };

        return array_map(static fn (array $texts): string => $texts[$index], self::definitions());
    }
}
