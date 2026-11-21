<?php

declare(strict_types=1);

namespace Modules\Quoting\Infrastructure\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Modules\Quoting\Domain\Contracts\QuoteDocumentRenderer;
use Symfony\Component\Process\Process;

/**
 * HTML -> PDF with Dompdf (fonts embedded, metadata set), then, when a Ghostscript binary is configured, converted to
 * PDF/A-1b (ISO 19005-1). Without Ghostscript the plain PDF is returned with pdfa_compliant=false: the claim is never
 * made for a file that was not converted.
 */
final class DompdfQuoteDocumentRenderer implements QuoteDocumentRenderer
{
    public function render(string $html, array $metadata): array
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        foreach (['Title' => 'title', 'Author' => 'author', 'Subject' => 'subject', 'Keywords' => 'keywords'] as $key => $field) {
            if (isset($metadata[$field])) {
                $dompdf->addInfo($key, $metadata[$field]);
            }
        }

        $bytes = $dompdf->output();
        $converted = $this->toPdfA($bytes);

        return ['bytes' => $converted ?? $bytes, 'pdfa_compliant' => $converted !== null];
    }

    private function toPdfA(string $pdf): ?string
    {
        $binary = config('quoting.pdfa.ghostscript_binary');
        $definition = config('quoting.pdfa.pdfa_def');

        if (! is_string($binary) || $binary === '' || ! is_string($definition) || $definition === '' || ! is_file($definition)) {
            return null;
        }

        $input = tempnam(sys_get_temp_dir(), 'cpq_in_');
        $output = tempnam(sys_get_temp_dir(), 'cpq_out_');

        if ($input === false || $output === false) {
            return null;
        }

        try {
            file_put_contents($input, $pdf);
            $process = new Process([
                $binary, '-dPDFA=1', '-dBATCH', '-dNOPAUSE', '-dNOOUTERSAVE', '-dPDFACompatibilityPolicy=1',
                '-sColorConversionStrategy=RGB', '-sProcessColorModel=DeviceRGB', '-sDEVICE=pdfwrite',
                '-sOutputFile='.$output, $definition, $input,
            ], null, null, null, 120);
            $process->run();

            $result = $process->isSuccessful() ? file_get_contents($output) : false;

            return is_string($result) && str_starts_with($result, '%PDF') ? $result : null;
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }
}
