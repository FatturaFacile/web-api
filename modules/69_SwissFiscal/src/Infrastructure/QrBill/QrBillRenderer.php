<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Infrastructure\QrBill;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Modules\SwissFiscal\Domain\Services\PaymentReference;
use Modules\SwissFiscal\Domain\Services\SwissIban;
use Modules\SwissFiscal\Domain\ValueObjects\QrBill;
use Modules\SwissFiscal\Domain\ValueObjects\QrBillAddress;

/**
 * Renders the QR code (error correction level M, Swiss cross overlay) and the A4 payment slip of a {@see QrBill}.
 * The slip wording is prescribed verbatim by the SIX standard for each language, so it lives with the renderer.
 */
final class QrBillRenderer
{
    /** Side of the QR SVG viewport; the Swiss cross is 7/46 of it. */
    private const int QrSize = 460;

    /** Wording mandated by the SIX Implementation Guidelines. */
    private const array Labels = [
        'en' => ['receipt' => 'Receipt', 'payment' => 'Payment part', 'account' => 'Account / Payable to', 'reference' => 'Reference', 'info' => 'Additional information', 'payable_by' => 'Payable by', 'payable_by_blank' => 'Payable by (name/address)', 'currency' => 'Currency', 'amount' => 'Amount', 'acceptance' => 'Acceptance point'],
        'it' => ['receipt' => 'Ricevuta', 'payment' => 'Sezione pagamento', 'account' => 'Conto / Pagabile a', 'reference' => 'Riferimento', 'info' => 'Informazioni aggiuntive', 'payable_by' => 'Pagabile da', 'payable_by_blank' => 'Pagabile da (nome/indirizzo)', 'currency' => 'Valuta', 'amount' => 'Importo', 'acceptance' => 'Punto di accettazione'],
        'de' => ['receipt' => 'Empfangsschein', 'payment' => 'Zahlteil', 'account' => 'Konto / Zahlbar an', 'reference' => 'Referenz', 'info' => 'Zusätzliche Informationen', 'payable_by' => 'Zahlbar durch', 'payable_by_blank' => 'Zahlbar durch (Name/Adresse)', 'currency' => 'Währung', 'amount' => 'Betrag', 'acceptance' => 'Annahmestelle'],
        'fr' => ['receipt' => 'Récépissé', 'payment' => 'Section paiement', 'account' => 'Compte / Payable à', 'reference' => 'Référence', 'info' => 'Informations supplémentaires', 'payable_by' => 'Payable par', 'payable_by_blank' => 'Payable par (nom/adresse)', 'currency' => 'Monnaie', 'amount' => 'Montant', 'acceptance' => 'Point de dépôt'],
    ];

    /** QR code as SVG with the Swiss cross in the centre. */
    public function qrSvg(string $payload): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(self::QrSize, 0), new SvgImageBackEnd));
        $svg = $writer->writeString($payload, 'UTF-8', ErrorCorrectionLevel::M());

        $centre = self::QrSize / 2;
        $square = round(self::QrSize * 7 / 46);
        $half = $square / 2;
        $arm = round($square * 0.62);
        $thickness = round($square * 0.2);
        $cross = sprintf(
            '<g><rect x="%1$s" y="%2$s" width="%3$s" height="%3$s" fill="#fff"/>'
            .'<rect x="%4$s" y="%5$s" width="%6$s" height="%6$s" fill="#000"/>'
            .'<rect x="%7$s" y="%8$s" width="%9$s" height="%10$s" fill="#fff"/>'
            .'<rect x="%8$s" y="%7$s" width="%10$s" height="%9$s" fill="#fff"/></g>',
            $centre - $half - 4,
            $centre - $half - 4,
            $square + 8,
            $centre - $half,
            $centre - $half,
            $square,
            $centre - $arm / 2,
            $centre - $thickness / 2,
            $arm,
            $thickness,
        );

        return str_replace('</svg>', $cross.'</svg>', $svg);
    }

    /** HTML of the payment part and receipt, laid out at the bottom of an A4 page (210 x 105 mm). */
    public function slipHtml(QrBill $bill, string $payload, string $language = 'en'): string
    {
        $labels = self::Labels[$language] ?? self::Labels['en'];
        $qr = base64_encode($this->qrSvg($payload));
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $reference = $bill->reference === null
            ? ''
            : ($bill->referenceType->value === 'QRR' ? PaymentReference::formatQrReference($bill->reference) : $bill->reference);
        $amount = $bill->amount === null ? '' : number_format($bill->amount->minor / 100, 2, '.', ' ');
        $debtor = $bill->debtor === null
            ? '<div class="blank"></div>'
            : $this->addressBlock($bill->debtor, $e);

        $creditorBlock = $e(SwissIban::format($bill->iban)).'<br>'.$this->addressBlock($bill->creditor, $e);
        $referenceBlock = $reference === '' ? '' : "<h6>{$e($labels['reference'])}</h6><p>{$e($reference)}</p>";
        $infoText = trim($bill->unstructuredMessage.' '.$bill->billInformation);
        $infoBlock = $infoText === '' ? '' : "<h6>{$e($labels['info'])}</h6><p>{$e($infoText)}</p>";

        return <<<HTML
<!DOCTYPE html><html><head><meta charset="utf-8"><style>
@page { size: 210mm 297mm; margin: 0; }
body { margin: 0; font-family: Helvetica, Arial, sans-serif; color: #000; }
.slip { position: absolute; left: 0; top: 192mm; width: 210mm; height: 105mm; border-top: 0.2mm dashed #000; }
.receipt { position: absolute; left: 0; top: 0; width: 52mm; height: 105mm; padding: 5mm; border-right: 0.2mm dashed #000; font-size: 8pt; }
.payment { position: absolute; left: 62mm; top: 0; width: 148mm; height: 105mm; font-size: 10pt; }
.qr { position: absolute; left: 5mm; top: 17mm; width: 46mm; height: 46mm; }
.amount { position: absolute; left: 5mm; top: 68mm; font-size: 10pt; }
.right { position: absolute; left: 70mm; top: 5mm; width: 72mm; }
h4 { margin: 0 0 4mm; font-size: 11pt; } h6 { margin: 3mm 0 0; font-size: 6pt; font-weight: bold; } p { margin: 0; font-size: 8pt; }
.receipt h6 { font-size: 6pt; } .receipt p { font-size: 8pt; } .blank { width: 50mm; height: 15mm; border: 0.2mm solid #000; }
</style></head><body><div class="slip">
<div class="receipt"><h4>{$e($labels['receipt'])}</h4>
<h6>{$e($labels['account'])}</h6><p>{$creditorBlock}</p>{$referenceBlock}
<h6>{$e($labels['payable_by'])}</h6><p>{$debtor}</p>
<h6>{$e($labels['currency'])} &nbsp;&nbsp; {$e($labels['amount'])}</h6><p>{$e($bill->currency->value)} &nbsp;&nbsp; {$e($amount)}</p>
<h6 style="text-align:right">{$e($labels['acceptance'])}</h6></div>
<div class="payment"><h4 style="margin-left:5mm;margin-top:5mm">{$e($labels['payment'])}</h4>
<img class="qr" src="data:image/svg+xml;base64,{$qr}">
<div class="amount"><h6>{$e($labels['currency'])} &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; {$e($labels['amount'])}</h6><p style="font-size:10pt">{$e($bill->currency->value)} &nbsp;&nbsp;&nbsp;&nbsp; {$e($amount)}</p></div>
<div class="right"><h6>{$e($labels['account'])}</h6><p style="font-size:10pt">{$creditorBlock}</p>{$referenceBlock}{$infoBlock}
<h6>{$e($labels['payable_by'])}</h6><p style="font-size:10pt">{$debtor}</p></div></div>
</div></body></html>
HTML;
    }

    /** PDF bytes of the A4 page carrying the payment slip. */
    public function slipPdf(QrBill $bill, string $payload, string $language = 'en'): string
    {
        return Pdf::loadHTML($this->slipHtml($bill, $payload, $language))->setPaper('a4')->output();
    }

    /**
     * @param  callable(string): string  $e
     */
    private function addressBlock(QrBillAddress $address, callable $e): string
    {
        $street = trim($address->street.' '.$address->buildingNumber);

        return implode('<br>', array_filter([
            $e($address->name),
            $street === '' ? '' : $e($street),
            $e(trim($address->postalCode.' '.$address->town)),
        ]));
    }
}
