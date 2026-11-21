<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Pdf;

use Modules\ItalianPayroll\Domain\Exceptions\ItalianPayrollException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Splits the bulk PDF of the consultant into one PDF per employee. The tax code on a page starts a new payslip; a page
 * without a code (the continuation of a long payslip) joins the previous one. Pages before the first code are returned
 * with a null tax code so that the caller can report them as unmatched instead of losing them.
 */
final class CedolinoBundleSplitter
{
    /**
     * @return list<array{tax_code: string|null, first_page: int, last_page: int, pdf: string}>
     */
    public function split(string $pdfBinary): array
    {
        $texts = $this->pageTexts($pdfBinary);

        return array_map(
            fn (array $group): array => $group + ['pdf' => $this->extractPages($pdfBinary, $group['first_page'], $group['last_page'])],
            $this->group($texts),
        );
    }

    /**
     * The grouping rule alone, on the text of each page (page 1 is index 0): testable without a PDF.
     *
     * @param  list<string>  $pageTexts
     * @return list<array{tax_code: string|null, first_page: int, last_page: int}>
     */
    public function group(array $pageTexts): array
    {
        $groups = [];

        foreach ($pageTexts as $index => $text) {
            $page = $index + 1;
            $code = CodiceFiscale::find($text);
            $last = count($groups) - 1;

            if ($code !== null && ($last < 0 || $groups[$last]['tax_code'] !== $code)) {
                $groups[] = ['tax_code' => $code, 'first_page' => $page, 'last_page' => $page];
            } elseif ($last >= 0) {
                $groups[$last]['last_page'] = $page;
            } else {
                $groups[] = ['tax_code' => null, 'first_page' => $page, 'last_page' => $page];
            }
        }

        return $groups;
    }

    /**
     * @return list<string>
     */
    private function pageTexts(string $pdfBinary): array
    {
        try {
            $pages = (new Parser)->parseContent($pdfBinary)->getPages();
        } catch (Throwable $exception) {
            throw new ItalianPayrollException('Il PDF dei cedolini non è leggibile: '.$exception->getMessage(), 0, $exception);
        }

        return array_map(static fn ($page): string => $page->getText(), array_values($pages));
    }

    private function extractPages(string $pdfBinary, int $first, int $last): string
    {
        try {
            $pdf = new Fpdi;
            $pdf->setSourceFile(StreamReader::createByString($pdfBinary));

            for ($page = $first; $page <= $last; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }

            return $pdf->Output('S');
        } catch (Throwable $exception) {
            throw new ItalianPayrollException('Impossibile separare le pagine del PDF: '.$exception->getMessage(), 0, $exception);
        }
    }
}
