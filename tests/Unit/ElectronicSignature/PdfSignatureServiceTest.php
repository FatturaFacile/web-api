<?php

declare(strict_types=1);

use Modules\ElectronicSignature\DTOs\SignatureAppearance;
use Modules\ElectronicSignature\Services\PdfSignatureService;
use Modules\ElectronicSignature\Services\PlatformSealService;
use Modules\ElectronicSignature\Services\SimpleDocumentRenderer;

function esigUnitPdf(): string
{
    return app(SimpleDocumentRenderer::class)->render('Unit agreement', [['Number', 'U-1']], [], ['Body text of the agreement.'], 'Footer');
}

function esigAppearance(string $name, ?array $box = null): SignatureAppearance
{
    return new SignatureAppearance($name, new DateTimeImmutable('2027-02-01 10:00:00', new DateTimeZone('UTC')), 'Unit test', 'Lugano', $box, ['Signed by '.$name]);
}

it('signs a PDF with an incremental update that verifies and names the signer', function (): void {
    $service = app(PdfSignatureService::class);
    $key = app(PlatformSealService::class)->generate('Unit Signer');
    $original = esigUnitPdf();

    $signed = $service->signWithKey($original, esigAppearance('Anna Rossi', ['page' => 1, 'x' => 0.1, 'y' => 0.8, 'width' => 0.3, 'height' => 0.08]), $key['certificate'], $key['key']);
    $signatures = $service->inspect($signed);

    expect($signed)->toStartWith('%PDF-')
        // An incremental update keeps every byte of the original in front of the signature.
        ->and(str_starts_with($signed, $original))->toBeTrue()
        ->and($signatures)->toHaveCount(1)
        ->and($signatures[0]->intact)->toBeTrue()
        ->and($signatures[0]->coversWholeDocument)->toBeTrue()
        ->and($signatures[0]->subFilter)->toBe('adbe.pkcs7.detached');
});

it('supports several signatures in a row and leaves the earlier ones valid', function (): void {
    $service = app(PdfSignatureService::class);
    $seal = app(PlatformSealService::class);
    $first = $seal->generate('First Signer');
    $second = $seal->generate('Second Signer');

    $once = $service->signWithKey(esigUnitPdf(), esigAppearance('Anna Rossi'), $first['certificate'], $first['key']);
    $twice = $service->signWithKey($once, esigAppearance('Bruno Bianchi'), $second['certificate'], $second['key']);
    $signatures = $service->inspect($twice);

    expect($signatures)->toHaveCount(2)
        ->and($signatures[0]->intact)->toBeTrue()
        ->and($signatures[1]->intact)->toBeTrue()
        ->and($signatures[0]->coversWholeDocument)->toBeFalse()
        ->and($signatures[1]->coversWholeDocument)->toBeTrue();
});

it('detects a change made to the document after it was signed', function (): void {
    $service = app(PdfSignatureService::class);
    $key = app(PlatformSealService::class)->generate('Unit Signer');
    $signed = $service->signWithKey(esigUnitPdf(), esigAppearance('Anna Rossi'), $key['certificate'], $key['key']);

    // One byte inside the signed range is enough: the stream content is compressed, so flip a byte of the header comment.
    $tampered = $signed;
    $tampered[10] = $tampered[10] === 'A' ? 'B' : 'A';

    expect($tampered)->not->toBe($signed);

    $signatures = $service->inspect($tampered);

    expect($signatures)->toHaveCount(1)->and($signatures[0]->intact)->toBeFalse();
});

it('reports a PDF without signatures as having none', function (): void {
    expect(app(PdfSignatureService::class)->inspect(esigUnitPdf()))->toBe([]);
});

it('hashes content with SHA-256', function (): void {
    expect(app(PdfSignatureService::class)->sha256('abc'))->toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
});

it('reads the size of every page so the wizard can place boxes as fractions of the page', function (): void {
    expect(app(PdfSignatureService::class)->pageSizes(esigUnitPdf()))->not->toBeEmpty();
});
