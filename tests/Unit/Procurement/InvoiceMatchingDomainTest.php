<?php

declare(strict_types=1);

use App\Models\PassiveInvoice;
use Modules\Procurement\DTOs\MatchTolerance;
use Modules\Procurement\Enums\InvoiceMatchLineStatus;
use Modules\Procurement\Enums\InvoiceMatchStatus;
use Modules\Procurement\Enums\ToleranceScope;
use Modules\Procurement\Services\PurchaseOrderReferenceExtractor;

function matchingTolerance48(string $percent, string $amount, string $qtyPercent = '0', string $qtyAmount = '0'): MatchTolerance
{
    return new MatchTolerance($percent, $amount, $qtyPercent, $qtyAmount);
}

it('allows the larger of the percentage and the absolute price tolerance', function (): void {
    // 1 % of 1,000 is 10, above the absolute 5.
    expect(matchingTolerance48('1', '5')->allowedAmount('1000.00'))->toBe('10.00')
        // 1 % of 100 is 1, below the absolute 5.
        ->and(matchingTolerance48('1', '5')->allowedAmount('100.00'))->toBe('5.00')
        ->and(matchingTolerance48('0', '0')->allowedAmount('1000.00'))->toBe('0.00')
        ->and(matchingTolerance48('2.5', '0')->allowedAmount('-400.00'))->toBe('10.00');
});

it('allows the larger of the percentage and the absolute quantity tolerance', function (): void {
    expect(matchingTolerance48('0', '0', '2', '0')->allowedQuantity('50.0000'))->toBe('1.0000')
        ->and(matchingTolerance48('0', '0', '2', '3')->allowedQuantity('50.0000'))->toBe('3.0000')
        ->and(matchingTolerance48('0', '0')->allowedQuantity('50.0000'))->toBe('0.0000');
});

it('describes the tolerance that was applied so a run keeps it as evidence', function (): void {
    $tolerance = new MatchTolerance('1.50', '5.00', '0.00', '0.0000', 'rule-1', 'supplier');

    expect($tolerance->toArray())->toBe([
        'source' => 'supplier',
        'rule_id' => 'rule-1',
        'price_tolerance_percent' => '1.50',
        'price_tolerance_amount' => '5.00',
        'quantity_tolerance_percent' => '0.00',
        'quantity_tolerance_amount' => '0.0000',
    ]);
});

it('ranks the tolerance scopes from the widest to the narrowest', function (): void {
    expect(ToleranceScope::Rule->specificity())->toBeGreaterThan(ToleranceScope::Supplier->specificity())
        ->and(ToleranceScope::Supplier->specificity())->toBeGreaterThan(ToleranceScope::ItemCategory->specificity())
        ->and(ToleranceScope::ItemCategory->specificity())->toBeGreaterThan(ToleranceScope::Tenant->specificity());
});

it('keeps the invoice out of the payment schedule only while the discrepancy is open', function (): void {
    expect(InvoiceMatchStatus::BlockedDiscrepancy->isBlocking())->toBeTrue()
        ->and(InvoiceMatchStatus::PendingCreditNote->isBlocking())->toBeTrue()
        ->and(InvoiceMatchStatus::Matched->isClearing())->toBeTrue()
        ->and(InvoiceMatchStatus::WithinTolerance->isClearing())->toBeTrue()
        ->and(InvoiceMatchStatus::OverrideApproved->isClearing())->toBeTrue()
        ->and(InvoiceMatchStatus::Resolved->isClearing())->toBeTrue()
        ->and(InvoiceMatchStatus::blockingValues())->toBe(['blocked_discrepancy', 'pending_credit_note'])
        ->and(InvoiceMatchStatus::clearingValues())->toBe(['matched', 'within_tolerance', 'override_approved', 'resolved']);
});

it('blocks the lines with a variance beyond tolerance, a missing receipt or no order line', function (): void {
    $blocking = array_filter(InvoiceMatchLineStatus::cases(), static fn (InvoiceMatchLineStatus $status): bool => $status->isBlocking());

    expect(array_map(static fn (InvoiceMatchLineStatus $status): string => $status->value, array_values($blocking)))
        ->toBe(InvoiceMatchLineStatus::blockingValues())
        ->and(InvoiceMatchLineStatus::Accepted->isBlocking())->toBeFalse()
        ->and(InvoiceMatchLineStatus::WithinTolerance->isBlocking())->toBeFalse();
});

it('reads the order reference of a FatturaPA invoice from DatiOrdineAcquisto only', function (): void {
    $xml = <<<'XML'
        <p:FatturaElettronica xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2">
          <FatturaElettronicaBody>
            <DatiGenerali>
              <DatiGeneraliDocumento><Numero>FT-77</Numero></DatiGeneraliDocumento>
              <DatiOrdineAcquisto><RiferimentoNumeroLinea>1</RiferimentoNumeroLinea><IdDocumento>PO-2027-0042</IdDocumento></DatiOrdineAcquisto>
              <DatiOrdineAcquisto><IdDocumento> PO-2027-0043 </IdDocumento></DatiOrdineAcquisto>
              <DatiContratto><IdDocumento>CONTRACT-9</IdDocumento></DatiContratto>
            </DatiGenerali>
          </FatturaElettronicaBody>
        </p:FatturaElettronica>
        XML;

    $references = (new PurchaseOrderReferenceExtractor)->extract((new PassiveInvoice)->forceFill(['xml_payload' => $xml]));

    expect($references)->toBe([
        ['value' => 'PO-2027-0042', 'source' => 'xml'],
        ['value' => 'PO-2027-0043', 'source' => 'xml'],
    ]);
});

it('reads the order reference of a Swiss eBill XML', function (): void {
    $xml = '<Invoice><Header><BuyerOrderNumber>BE-5521</BuyerOrderNumber></Header><Payment><OrderReference>BE-5521</OrderReference></Payment></Invoice>';

    $references = (new PurchaseOrderReferenceExtractor)->extract((new PassiveInvoice)->forceFill(['xml_payload' => $xml]));

    // The same reference quoted twice is reported once.
    expect($references)->toBe([['value' => 'BE-5521', 'source' => 'ebill']]);
});

it('finds no structured reference on an invoice without a payload', function (): void {
    expect((new PurchaseOrderReferenceExtractor)->extract(new PassiveInvoice))->toBe([]);
});

it('offers the XML and the OCR text as searchable haystacks, without separators and in capitals', function (): void {
    $invoice = (new PassiveInvoice)->forceFill([
        'xml_payload' => '<Invoice><Note>Order po-2027/0042</Note></Invoice>',
        'qr_raw_payload' => 'Rechnung zu Bestellung PO 2027 0042',
    ]);

    $haystacks = (new PurchaseOrderReferenceExtractor)->haystacks($invoice);

    expect($haystacks)->toHaveCount(2)
        ->and($haystacks[0]['source'])->toBe('ebill')
        ->and($haystacks[0]['text'])->toContain('PO20270042')
        ->and($haystacks[1])->toBe(['text' => 'RECHNUNGZUBESTELLUNGPO20270042', 'source' => 'ocr']);
});

it('normalizes order numbers for the comparison', function (): void {
    expect(PurchaseOrderReferenceExtractor::normalize('po-2027/0042 '))->toBe('PO20270042');
});
