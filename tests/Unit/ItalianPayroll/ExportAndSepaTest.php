<?php

declare(strict_types=1);

use Modules\ItalianPayroll\Domain\Enums\CausalCode;
use Modules\ItalianPayroll\Domain\Enums\ConsultantFormat;
use Modules\ItalianPayroll\Domain\Exceptions\ItalianPayrollException;
use Modules\ItalianPayroll\Infrastructure\Export\ExportAdapterRegistry;
use Modules\ItalianPayroll\Infrastructure\Export\ExportRow;
use Modules\ItalianPayroll\Infrastructure\Export\InazExportAdapter;
use Modules\ItalianPayroll\Infrastructure\Export\ZucchettiExportAdapter;
use Modules\ItalianPayroll\Infrastructure\Sepa\SalaryPain001Generator;

function pipRows(ConsultantFormat $format): array
{
    return [
        new ExportRow('00123', 'MAL', $format->defaultVoiceCode(CausalCode::Malattia), '5.00', 'G', 0, '0.00', '0.00', '12345678901'),
        new ExportRow('00123', 'ORD', $format->defaultVoiceCode(CausalCode::Ordinario), '152.50', 'H', 18, '45.30', '120.00', null),
    ];
}

it('writes Zucchetti records of exactly 80 columns', function (): void {
    $file = (new ZucchettiExportAdapter)->render(2027, 3, 'ACME', pipRows(ConsultantFormat::Zucchetti));
    $lines = array_filter(explode("\r\n", $file->content));

    expect($lines)->toHaveCount(2);

    foreach ($lines as $line) {
        expect(strlen($line))->toBe(ZucchettiExportAdapter::RecordLength);
    }

    expect(array_values($lines)[0])->toStartWith('ACME  202703'.'00123     '.'MALA  '.'000000500G');
});

it('writes Inaz records of exactly 65 columns', function (): void {
    $file = (new InazExportAdapter)->render(2027, 3, 'ACME', pipRows(ConsultantFormat::Inaz));

    foreach (array_filter(explode("\r\n", $file->content)) as $line) {
        expect(strlen($line))->toBe(InazExportAdapter::RecordLength);
    }
});

it('renders every consultant format with the INPS protocol of the sickness line', function (): void {
    $registry = new ExportAdapterRegistry;

    foreach (ConsultantFormat::cases() as $format) {
        $file = $registry->for($format)->render(2027, 3, 'ACME', pipRows($format));

        expect($file->content)->toContain('12345678901')
            ->and($file->checksum())->toHaveLength(64);
    }

    $gis = $registry->for(ConsultantFormat::Gis)->render(2027, 3, 'ACME', pipRows(ConsultantFormat::Gis));
    expect(simplexml_load_string($gis->content))->not->toBeFalse();
});

it('quotes CSV fields that contain the separator', function (): void {
    $row = new ExportRow('1', 'ORD', 'ORD', '1', 'H', 0, '0', '0', 'a;b');
    $csv = $registry = (new ExportAdapterRegistry)->for(ConsultantFormat::GenericCsv)->render(2027, 3, 'ACME', [$row]);

    expect($csv->content)->toContain('"a;b"')->and($csv->content)->toStartWith("\xEF\xBB\xBF");
});

it('produces a pain.001.001.03 file with SALA purpose and exact totals', function (): void {
    $xml = (new SalaryPain001Generator)->generate('SAL-202703-X', '2027-03-27', ['name' => 'Acme Srl', 'iban' => 'IT60X0542811101000000123456', 'bic' => 'BCITITMM'], [
        ['name' => 'Mario Rossi', 'iban' => 'IT60X0542811101000000123456', 'amount' => '1500.10', 'end_to_end_id' => 'E1', 'remittance' => 'Stipendio 03/2027'],
        ['name' => 'Lara Bianchi', 'iban' => 'DE89370400440532013000', 'amount' => '999.90', 'end_to_end_id' => 'E2', 'remittance' => 'Stipendio 03/2027'],
    ]);

    $document = new DOMDocument;
    $document->loadXML($xml);
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('p', SalaryPain001Generator::XmlNamespace);

    expect($document->documentElement->namespaceURI)->toBe('urn:iso:std:iso:20022:tech:xsd:pain.001.001.03')
        ->and($xpath->evaluate('string(//p:PmtTpInf/p:CtgyPurp/p:Cd)'))->toBe('SALA')
        ->and($xpath->evaluate('string(//p:PmtTpInf/p:SvcLvl/p:Cd)'))->toBe('SEPA')
        ->and($xpath->evaluate('string(//p:GrpHdr/p:NbOfTxs)'))->toBe('2')
        ->and($xpath->evaluate('string(//p:GrpHdr/p:CtrlSum)'))->toBe('2500.00')
        ->and($xpath->evaluate('count(//p:CdtTrfTxInf)'))->toBe(2.0)
        ->and($xpath->evaluate('string(//p:CdtTrfTxInf[1]/p:Amt/p:InstdAmt)'))->toBe('1500.10')
        ->and($xpath->evaluate('string(//p:CdtTrfTxInf[1]/p:Amt/p:InstdAmt/@Ccy)'))->toBe('EUR');
});

it('refuses an invalid IBAN or an empty payment list', function (): void {
    $generator = new SalaryPain001Generator;
    $debtor = ['name' => 'Acme Srl', 'iban' => 'IT60X0542811101000000123456'];

    expect(fn () => $generator->generate('M', '2027-03-27', $debtor, [['name' => 'X', 'iban' => 'IT00INVALID', 'amount' => '1.00', 'end_to_end_id' => 'E', 'remittance' => 'r']]))
        ->toThrow(ItalianPayrollException::class, 'IBAN')
        ->and(fn () => $generator->generate('M', '2027-03-27', $debtor, []))->toThrow(ItalianPayrollException::class);
});
