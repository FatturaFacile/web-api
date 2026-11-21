<?php

declare(strict_types=1);

use Modules\ItalianPayroll\Infrastructure\Pdf\CedolinoBundleSplitter;
use Modules\ItalianPayroll\Infrastructure\Pdf\CodiceFiscale;
use setasign\Fpdi\Fpdi;
use Smalot\PdfParser\Parser;

const PIP_CF_ROSSI = 'RSSMRA85T10A562S';
const PIP_CF_BIANCHI = 'BNCLRA90A41H501F';

/** A synthetic PDF with one text line per page. */
function pipPdf(array $pageTexts): string
{
    $pdf = new Fpdi;

    foreach ($pageTexts as $text) {
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(0, 10, $text);
    }

    return $pdf->Output('S');
}

it('validates codice fiscale with its control character', function (): void {
    expect(CodiceFiscale::isValid(PIP_CF_ROSSI))->toBeTrue()
        ->and(CodiceFiscale::isValid(strtolower(PIP_CF_ROSSI)))->toBeTrue()
        ->and(CodiceFiscale::isValid('RSSMRA85T10A562X'))->toBeFalse()
        ->and(CodiceFiscale::isValid('NOTACODE'))->toBeFalse();
});

it('finds only valid codes in free text', function (): void {
    $text = 'Cedolino Mario Rossi C.F. '.PIP_CF_ROSSI.' protocollo ABCDEF12G34H567I';

    expect(CodiceFiscale::findAll($text))->toBe([PIP_CF_ROSSI])
        ->and(CodiceFiscale::find('nessun codice qui'))->toBeNull();
});

it('groups continuation pages with the payslip they belong to', function (): void {
    $groups = (new CedolinoBundleSplitter)->group([
        'Frontespizio senza codice',
        'Rossi '.PIP_CF_ROSSI,
        'segue pagina due',
        'Bianchi '.PIP_CF_BIANCHI,
    ]);

    expect($groups)->toBe([
        ['tax_code' => null, 'first_page' => 1, 'last_page' => 1],
        ['tax_code' => PIP_CF_ROSSI, 'first_page' => 2, 'last_page' => 3],
        ['tax_code' => PIP_CF_BIANCHI, 'first_page' => 4, 'last_page' => 4],
    ]);
});

it('splits a real PDF bundle into one valid PDF per employee', function (): void {
    $bundle = pipPdf(['Rossi '.PIP_CF_ROSSI, 'Rossi pagina 2', 'Bianchi '.PIP_CF_BIANCHI]);

    $parts = (new CedolinoBundleSplitter)->split($bundle);

    expect($parts)->toHaveCount(2)
        ->and($parts[0]['tax_code'])->toBe(PIP_CF_ROSSI)
        ->and($parts[1]['tax_code'])->toBe(PIP_CF_BIANCHI);

    foreach ($parts as $part) {
        expect(str_starts_with($part['pdf'], '%PDF'))->toBeTrue();
    }

    $first = (new Parser)->parseContent($parts[0]['pdf']);
    expect($first->getPages())->toHaveCount(2)
        ->and($first->getText())->toContain(PIP_CF_ROSSI);
    expect((new Parser)->parseContent($parts[1]['pdf'])->getPages())->toHaveCount(1);
});
