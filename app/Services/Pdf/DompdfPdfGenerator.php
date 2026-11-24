<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use App\Contracts\PdfGenerator;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * dompdf adapter. Fonts, UTF-8 handling and security options (no remote assets, no inline PHP) come from `config/dompdf.php`.
 */
class DompdfPdfGenerator implements PdfGenerator
{
    public function render(string $view, array $data = []): string
    {
        return Pdf::loadView($view, $data)->setPaper('a4')->output();
    }
}
