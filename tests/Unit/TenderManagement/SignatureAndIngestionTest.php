<?php

declare(strict_types=1);

use Modules\TenderManagement\Exceptions\TenderException;
use Modules\TenderManagement\Services\NoticeService;
use Modules\TenderManagement\Services\SignatureInspector;

/**
 * A minimal DER CAdES envelope: a SEQUENCE carrying the signedData OID.
 */
function tndCades(): string
{
    return "\x30\x82\x01\x00\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x07\x02".str_repeat("\x00", 64);
}

/**
 * A PDF with a PAdES signature dictionary.
 */
function tndPades(): string
{
    return "%PDF-1.7\n1 0 obj\n<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /ETSI.CAdES.detached /ByteRange [0 100 200 300] /M (D:20260615100000+02'00') /Name (Mario Rossi) /Contents <00> >>\nendobj\n%%EOF";
}

it('recognises a CAdES .p7m envelope in DER and base64 form', function (): void {
    $inspector = new SignatureInspector;
    $der = $inspector->inspect(tndCades(), 'offer.pdf.p7m', true);
    $base64 = $inspector->inspect(base64_encode(tndCades()), 'offer.pdf.p7m', true);

    expect($der['status'])->toBe('valid')->and($der['format'])->toBe('p7m')
        ->and($base64['status'])->toBe('valid');
});

it('rejects a .p7m file that is not a PKCS#7 signedData', function (): void {
    $result = (new SignatureInspector)->inspect('not a signature at all', 'offer.pdf.p7m', true);

    expect($result['status'])->toBe('invalid')->and($result['message_code'])->toBe('cades_malformed');
});

it('reads the signer and the date of a PAdES signed PDF', function (): void {
    $result = (new SignatureInspector)->inspect(tndPades(), 'offer.pdf', true);

    expect($result['format'])->toBe('pades')
        ->and($result['status'])->toBe('valid')
        ->and($result['signer'])->toBe('Mario Rossi')
        ->and($result['signed_at'])->toStartWith('2026-06-15');
});

it('reports an unsigned PDF as unsigned when the signature is required and as not required otherwise', function (): void {
    $inspector = new SignatureInspector;
    $pdf = "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";

    expect($inspector->inspect($pdf, 'doc.pdf', true)['status'])->toBe('unsigned')
        ->and($inspector->inspect($pdf, 'doc.pdf', false)['status'])->toBe('not_required')
        ->and($inspector->inspect('plain text', 'doc.txt', true)['status'])->toBe('unsigned')
        ->and($inspector->isSignatureFormat('doc.txt'))->toBeFalse()
        ->and($inspector->isSignatureFormat('doc.p7m'))->toBeTrue();
});

it('maps a simap.ch publication payload to a Swiss tender notice', function (): void {
    $notice = new NoticeService;

    // The payload parser is exercised through reflection of its pure helpers: the persistence path is covered by the feature test.
    $method = new ReflectionMethod($notice, 'cpvCodes');
    $codes = $method->invoke($notice, [['code' => '50700000-2'], '71314000', 'invalid']);
    $procedure = new ReflectionMethod($notice, 'procedure');

    expect($codes)->toBe(['50700000', '71314000'])
        ->and($procedure->invoke($notice, 'Selective procedure'))->toBe('selective')
        ->and($procedure->invoke($notice, 'freihändiges Verfahren'))->toBe('direct_award')
        ->and($procedure->invoke($notice, 'Offenes Verfahren'))->toBe('open');
});

it('refuses a simap.ch payload without project number and title', function (): void {
    (new NoticeService)->ingestSimap(['description' => 'x'], null);
})->throws(TenderException::class, 'simap_payload_invalid');
