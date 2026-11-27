<?php

declare(strict_types=1);

use Modules\Treasury\Services\PaymentFlows\Pain002\Pain002Parser;
use Modules\Treasury\Services\PaymentFlows\Pain002\Pain002Report;

function pain002Fixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/../../Fixtures/Iso20022/'.$name);
}

test('a pain.002.001.03 report is read with its group status, transactions and rejection reason', function (): void {
    $report = (new Pain002Parser)->parse(pain002Fixture('pain.002.001.03.partially-accepted.xml'));

    expect($report->messageId)->toBe('STS-20260315-0001')
        ->and($report->originalMessageId)->toBe('MSG-20260314-AB12CD34')
        ->and($report->originalMessageType)->toBe('pain.001.001.03')
        ->and($report->groupStatus)->toBe(Pain002Report::PARTIALLY_ACCEPTED)
        ->and($report->paymentInformation)->toHaveKey('PAY-20260314-X1Y2Z3')
        ->and($report->transactions)->toHaveCount(3);

    $rejected = $report->transactionFor('E2E-0002', null);

    expect($rejected?->isRejected())->toBeTrue()
        ->and($rejected?->reasonCode)->toBe('AC04')
        ->and($rejected?->additionalInfo)->toBe('Account of the creditor is closed')
        ->and($report->transactionFor('E2E-0001', null)?->status)->toBe('ACCP')
        ->and($report->transactionFor('E2E-0003', null)?->status)->toBe('ACTC');
});

test('a transaction is also found by its instruction identifier', function (): void {
    $report = (new Pain002Parser)->parse(pain002Fixture('pain.002.001.03.partially-accepted.xml'));

    expect($report->transactionFor('UNKNOWN-E2E', 'INSTR-0002')?->reasonCode)->toBe('AC04')
        ->and($report->transactionFor('UNKNOWN-E2E', 'UNKNOWN-INSTR'))->toBeNull();
});

test('a pain.002.001.10 file rejection keeps every additional information line', function (): void {
    $report = (new Pain002Parser)->parse(pain002Fixture('pain.002.001.10.rejected-group.xml'));

    expect($report->originalMessageId)->toBe('MSG-20260315-FF00AA11')
        ->and($report->groupStatus)->toBe(Pain002Report::REJECTED)
        ->and($report->groupReasonCode)->toBe('FF01')
        ->and($report->groupAdditionalInfo)->toBe('Invalid file format Element PmtInf/ReqdExctnDt is not allowed')
        ->and($report->transactions)->toBe([]);
});

test('a transaction without a status inherits the one of its payment information block', function (): void {
    $xml = <<<'XML'
        <Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.002.001.03"><CstmrPmtStsRpt>
        <GrpHdr><MsgId>S1</MsgId></GrpHdr>
        <OrgnlGrpInfAndSts><OrgnlMsgId>M1</OrgnlMsgId></OrgnlGrpInfAndSts>
        <OrgnlPmtInfAndSts><OrgnlPmtInfId>P1</OrgnlPmtInfId><PmtInfSts>RJCT</PmtInfSts>
        <StsRsnInf><Rsn><Prtry>BANK-77</Prtry></Rsn></StsRsnInf>
        <TxInfAndSts><OrgnlEndToEndId>E1</OrgnlEndToEndId></TxInfAndSts></OrgnlPmtInfAndSts>
        </CstmrPmtStsRpt></Document>
        XML;
    $report = (new Pain002Parser)->parse($xml);

    expect($report->groupStatus)->toBe(Pain002Report::REJECTED)
        ->and($report->transactionFor('E1', null)?->status)->toBe(Pain002Report::REJECTED)
        ->and($report->transactionFor('E1', null)?->reasonCode)->toBe('BANK-77');
});

test('anything that is not a pain.002 report is refused', function (string $xml): void {
    expect(fn () => (new Pain002Parser)->parse($xml))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [''],
    'not xml' => ['hello'],
    'other message' => ['<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.04"><BkToCstmrDbtCdtNtfctn/></Document>'],
    'entity declaration' => ['<!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]><Document><OrgnlGrpInfAndSts><OrgnlMsgId>&x;</OrgnlMsgId></OrgnlGrpInfAndSts></Document>'],
]);
