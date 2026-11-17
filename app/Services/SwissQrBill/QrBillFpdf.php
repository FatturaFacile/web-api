<?php

declare(strict_types=1);

namespace App\Services\SwissQrBill;

use Fpdf\Fpdf;
use Sprain\SwissQrBill\PaymentPart\Output\FpdfOutput\FpdfTrait;

/**
 * FPDF with the dash and rotation helpers the SIX payment slip output needs for perforation lines and scissors.
 */
final class QrBillFpdf extends Fpdf
{
    use FpdfTrait;
}
