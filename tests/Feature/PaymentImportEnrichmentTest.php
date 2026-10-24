<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'FatturaFacile Switzerland AG',
        'legal_name' => 'FatturaFacile Switzerland AG',
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);

    $user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);
});

function feeXml(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.04">
  <BkToCstmrDbtCdtNtfctn><Ntfctn>
    <Ntry>
      <Amt Ccy="CHF">100.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-03-10</Dt></BookgDt>
      <NtryDtls><TxDtls>
        <Refs><AcctSvcrRef>QR-1</AcctSvcrRef></Refs>
        <Chrgs><TtlChrgsAndTaxAmt Ccy="CHF">1.50</TtlChrgsAndTaxAmt></Chrgs>
        <RmtInf><Strd><CdtrRefInf><Tp><CdOrPrtry><Prtry>QRR</Prtry></CdOrPrtry></Tp><Ref>210000000003139471430009017</Ref></CdtrRefInf></Strd></RmtInf>
      </TxDtls></NtryDtls>
    </Ntry>
    <Ntry>
      <Amt Ccy="CHF">50.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-03-11</Dt></BookgDt>
      <Chrgs><Rcrd><Amt Ccy="CHF">0.80</Amt></Rcrd><Rcrd><Amt Ccy="CHF">0.20</Amt></Rcrd></Chrgs>
      <NtryDtls><TxDtls>
        <Refs><AcctSvcrRef>ISR-1</AcctSvcrRef></Refs>
        <RmtInf><Strd><CdtrRefInf><Tp><CdOrPrtry><Prtry>ISR Reference</Prtry></CdOrPrtry></Tp><Ref>000000000000000000000012345</Ref></CdtrRefInf></Strd></RmtInf>
      </TxDtls></NtryDtls>
    </Ntry>
    <Ntry>
      <Amt Ccy="CHF">25.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-03-12</Dt></BookgDt>
      <NtryDtls><TxDtls>
        <Refs><AcctSvcrRef>RF-1</AcctSvcrRef></Refs>
        <RmtInf><Strd><CdtrRefInf><Ref>RF18 5390 0754 7034</Ref></CdtrRefInf></Strd></RmtInf>
      </TxDtls></NtryDtls>
    </Ntry>
    <Ntry>
      <Amt Ccy="CHF">10.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-03-13</Dt></BookgDt>
      <NtryDtls><TxDtls><Refs><AcctSvcrRef>FREE-1</AcctSvcrRef></Refs><RmtInf><Ustrd>Free text deposit</Ustrd></RmtInf></TxDtls></NtryDtls>
    </Ntry>
  </Ntfctn></BkToCstmrDbtCdtNtfctn>
</Document>
XML;
}

test('bank charges and the payment channel are read from camt entries', function (): void {
    $import = $this->withHeader('X-Tenant-ID', $this->tenant->id)
        ->postJson('/api/v1/sales/payment-imports', ['file' => UploadedFile::fake()->createWithContent('camt054.xml', feeXml())])
        ->assertCreated();

    $lines = collect($import->json('data.lines'))->keyBy(fn (array $line): string => substr($line['booking_date'], 0, 10));

    expect((float) $import->json('data.total_fees'))->toEqual(2.5)
        ->and($lines->get('2026-03-10')['reference_type'])->toBe('qr')
        ->and((float) $lines->get('2026-03-10')['fee_amount'])->toEqual(1.5)
        ->and($lines->get('2026-03-11')['reference_type'])->toBe('pvr')
        ->and((float) $lines->get('2026-03-11')['fee_amount'])->toEqual(1.0)
        ->and($lines->get('2026-03-12')['reference_type'])->toBe('scor')
        ->and((float) $lines->get('2026-03-12')['fee_amount'])->toEqual(0.0)
        ->and($lines->get('2026-03-13')['reference_type'])->toBeNull();
});

test('re-importing the same file does not double count the fees', function (): void {
    $upload = fn () => ['file' => UploadedFile::fake()->createWithContent('camt054.xml', feeXml())];
    $api = $this->withHeader('X-Tenant-ID', $this->tenant->id);

    $api->postJson('/api/v1/sales/payment-imports', $upload())->assertCreated();
    $again = $api->postJson('/api/v1/sales/payment-imports', $upload())->assertCreated();

    expect((float) $again->json('data.total_fees'))->toEqual(0.0)->and($again->json('data.duplicate_entries'))->toBe(4);
});

test('invalid bank files return the translated error message', function (): void {
    $this->withHeader('X-Tenant-ID', $this->tenant->id)
        ->postJson('/api/v1/sales/payment-imports', ['file' => UploadedFile::fake()->createWithContent('bad.xml', '<foo/>')])
        ->assertUnprocessable()
        ->assertJsonFragment(['detail' => 'The file is not a valid camt.053/camt.054 document.']);
});
