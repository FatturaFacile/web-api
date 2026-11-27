<?php

declare(strict_types=1);

use App\Actions\Sales\RecordPayment;
use App\Models\InvoiceQrBillData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\SwissEbill\Models\EbillTransmission;

require_once __DIR__.'/EbillSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ebSetUp($this);
    $this->invoice = ebInvoice(ebCustomer());
    $this->transmission = ebSend($this, $this->invoice);
});

/**
 * A camt.054 notification with one credit; the remittance text carries what the e-banking passes along.
 */
function ebCamt054(string $amount, string $remittance, string $bankRef = 'BANK-REF-1'): string
{
    return '<?xml version="1.0" encoding="UTF-8"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.04"><BkToCstmrDbtCdtNtfctn><Ntfctn>'
        .'<Ntry><Amt Ccy="CHF">'.$amount.'</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-03-10</Dt></BookgDt><ValDt><Dt>2026-03-11</Dt></ValDt>'
        .'<NtryDtls><TxDtls><Refs><AcctSvcrRef>'.$bankRef.'</AcctSvcrRef></Refs><RmtInf><Ustrd>'.$remittance.'</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>'
        .'</Ntfctn></BkToCstmrDbtCdtNtfctn></Document>';
}

test('a full payment settles the eBill with the bank entry, the value date and the amount', function (): void {
    app(RecordPayment::class)->execute($this->invoice, ['amount' => 108.1, 'paid_at' => '2026-03-10', 'reference' => 'BANK-ENTRY-77']);

    $transmission = $this->transmission->refresh();

    expect($transmission->status->value)->toBe('paid_reconciled')
        ->and($transmission->camt054_entry_id)->toBe('BANK-ENTRY-77')
        ->and($transmission->settlement_value_date?->toDateString())->toBe('2026-03-10')
        ->and((float) $transmission->settled_amount)->toBe(108.1)
        ->and($transmission->amount_discrepancy)->toBeNull()
        ->and($transmission->paid_at)->not->toBeNull()
        ->and($this->invoice->refresh()->ebill_status)->toBe('paid_reconciled')
        ->and($transmission->events()->where('event_type', 'paid_reconciled')->first()->source)->toBe('camt054');
});

test('a partial payment is recorded and flagged, the rest settles it', function (): void {
    app(RecordPayment::class)->execute($this->invoice, ['amount' => 50.0, 'paid_at' => '2026-03-10', 'reference' => 'PART-1']);

    $transmission = $this->transmission->refresh();

    expect($transmission->status->value)->toBe('transmitted')
        ->and((float) $transmission->settled_amount)->toBe(50.0)
        ->and((float) $transmission->amount_discrepancy)->toBe(58.1)
        ->and($transmission->events()->pluck('event_type')->all())->toContain('partial_settlement')
        ->and($transmission->events()->where('event_type', 'partial_settlement')->first()->details['open_difference'])->toBe(58.1);

    app(RecordPayment::class)->execute($this->invoice->refresh(), ['amount' => 58.1, 'paid_at' => '2026-03-12', 'reference' => 'PART-2']);

    $transmission->refresh();

    expect($transmission->status->value)->toBe('paid_reconciled')
        ->and((float) $transmission->settled_amount)->toBe(108.1)
        ->and($transmission->amount_discrepancy)->toBeNull()
        ->and($transmission->camt054_entry_id)->toBe('PART-2')
        ->and($transmission->settlement_value_date?->toDateString())->toBe('2026-03-12');
});

test('a credit above the amount of the eBill is flagged as a discrepancy', function (): void {
    $this->transmission->forceFill(['invoice_amount' => 100.0])->save();

    app(RecordPayment::class)->execute($this->invoice, ['amount' => 108.1, 'paid_at' => '2026-03-10', 'reference' => 'OVER-1']);

    $transmission = $this->transmission->refresh();

    expect($transmission->status->value)->toBe('paid_reconciled')->and((float) $transmission->amount_discrepancy)->toBe(-8.1);
});

test('a payment settles an eBill that is approved but not yet reported paid', function (): void {
    ebAdvance($this, 5);
    $this->artisan('ebill:poll-pending-statuses')->assertSuccessful();
    expect($this->transmission->refresh()->status->value)->toBe('approved_by_debtor');

    app(RecordPayment::class)->execute($this->invoice, ['amount' => 108.1, 'paid_at' => '2026-03-10', 'reference' => 'APP-1']);

    expect($this->transmission->refresh()->status->value)->toBe('paid_reconciled');
});

test('a payment on an invoice without an eBill, or after a rejection, leaves the eBill modules alone', function (): void {
    $plain = ebInvoice(ebCustomer());
    app(RecordPayment::class)->execute($plain, ['amount' => 108.1, 'paid_at' => '2026-03-10', 'reference' => 'PLAIN-1']);
    expect(EbillTransmission::query()->where('invoice_id', $plain->id)->exists())->toBeFalse()->and($plain->refresh()->payment_status)->toBe('paid');

    ebWebhook($this, $this->setting->refresh(), ['type' => 'rejected', 'event_id' => 'rej-1', 'transaction_id' => $this->transmission->six_transaction_id, 'rejection_code' => 'ALREADY_PAID'])->assertOk();
    app(RecordPayment::class)->execute($this->invoice, ['amount' => 108.1, 'paid_at' => '2026-03-10', 'reference' => 'LATE-1']);

    expect($this->transmission->refresh()->status->value)->toBe('rejected_by_debtor')->and($this->invoice->refresh()->payment_status)->toBe('paid');
});

test('a camt.054 credit quoting the EndToEndId reconciles the eBill through the bank import', function (string $reference): void {
    $key = $reference === 'idempotency' ? $this->transmission->idempotency_key : (string) $this->transmission->six_transaction_id;
    $xml = ebCamt054('108.10', 'eBill payment '.$key);

    $import = $this->postJson('/api/v1/sales/payment-imports', ['file' => UploadedFile::fake()->createWithContent('camt054.xml', $xml)])->assertCreated();

    expect($import->json('data.matched_entries'))->toBe(1);

    $transmission = $this->transmission->refresh();

    expect($transmission->status->value)->toBe('paid_reconciled')
        ->and($transmission->camt054_entry_id)->not->toBeNull()
        ->and($transmission->settlement_value_date?->toDateString())->toBe('2026-03-10')
        ->and((float) $transmission->settled_amount)->toBe(108.1)
        ->and($this->invoice->refresh()->payment_status)->toBe('paid');
})->with(['the idempotency key (EndToEndId)' => ['idempotency'], 'the SIX transaction id' => ['transaction']]);

test('a camt.054 credit with the QR reference of the invoice reconciles it too', function (): void {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.04"><BkToCstmrDbtCdtNtfctn><Ntfctn><Ntry>'
        .'<Amt Ccy="CHF">108.10</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-03-10</Dt></BookgDt>'
        .'<NtryDtls><TxDtls><Refs><AcctSvcrRef>QR-ENTRY-1</AcctSvcrRef></Refs><RmtInf><Strd><CdtrRefInf><Tp><CdOrPrtry><Prtry>QRR</Prtry></CdOrPrtry></Tp><Ref>'.EBILL_QRR.'</Ref></CdtrRefInf></Strd></RmtInf></TxDtls></NtryDtls>'
        .'</Ntry></Ntfctn></BkToCstmrDbtCdtNtfctn></Document>';

    // The QR-bill remembers its reference on the invoice (qr_bill_data); make sure it is there.
    InvoiceQrBillData::query()->updateOrCreate(['invoice_id' => $this->invoice->id], ['qr_iban' => EBILL_QR_IBAN, 'scor_reference' => EBILL_QRR, 'creditor_info' => [], 'debtor_info' => []]);

    $this->postJson('/api/v1/sales/payment-imports', ['file' => UploadedFile::fake()->createWithContent('camt054.xml', $xml)])->assertCreated();

    expect($this->transmission->refresh()->status->value)->toBe('paid_reconciled');
});

test('an entry that quotes nothing of the eBill stays unmatched', function (): void {
    $import = $this->postJson('/api/v1/sales/payment-imports', ['file' => UploadedFile::fake()->createWithContent('camt054.xml', ebCamt054('108.10', 'Some other payment'))])->assertCreated();

    expect($import->json('data.matched_entries'))->toBe(0)->and($this->transmission->refresh()->status->value)->toBe('transmitted');
});
