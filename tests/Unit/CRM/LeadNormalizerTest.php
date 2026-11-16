<?php

declare(strict_types=1);

namespace Tests\Unit\CRM;

use Modules\CRM\Services\LeadNormalizer;

beforeEach(function (): void {
    $this->normalizer = new LeadNormalizer;
});

test('normalizes Italian phone numbers to E.164', function (string $input, ?string $expected): void {
    expect($this->normalizer->normalizePhone($input, 'IT'))->toBe($expected);
})->with([
    'mobile without prefix' => ['333 123 4567', '+393331234567'],
    'mobile starting with 39' => ['392 1234567', '+393921234567'],
    'landline keeps trunk zero' => ['02 8887771', '+39028887771'],
    'international plus' => ['+39 02 8887771', '+39028887771'],
    'international 00' => ['0039 333 1234567', '+393331234567'],
    'country code without plus' => ['393331234567', '+393331234567'],
    'punctuation' => ['(333) 123-45.67', '+393331234567'],
    'too short' => ['12345', null],
    'letters only' => ['n/a', null],
]);

test('normalizes Swiss phone numbers to E.164 dropping the trunk zero', function (string $input, ?string $expected): void {
    expect($this->normalizer->normalizePhone($input, 'CH'))->toBe($expected);
})->with([
    'landline' => ['091 123 45 67', '+41911234567'],
    'mobile' => ['079 123 45 67', '+41791234567'],
    'with country code' => ['41791234567', '+41791234567'],
    'international' => ['+41 79 123 45 67', '+41791234567'],
]);

test('uses Italy as default country and returns null for unsupported countries', function (): void {
    expect($this->normalizer->normalizePhone('333 1234567'))->toBe('+393331234567')
        ->and($this->normalizer->normalizePhone('0301234567', 'DE'))->toBe('+49301234567')
        ->and($this->normalizer->normalizePhone('5551234', 'ZZ'))->toBeNull()
        ->and($this->normalizer->normalizePhone(null))->toBeNull()
        ->and($this->normalizer->normalizePhone('   '))->toBeNull();
});

test('normalizes VAT numbers and builds IT prefix variants', function (): void {
    expect($this->normalizer->normalizeVat(' it 123 456 789-01 '))->toBe('IT12345678901')
        ->and($this->normalizer->normalizeVat('CHE-123.456.789 MWST'))->toBe('CHE123456789')
        ->and($this->normalizer->normalizeVat('CHE-123.456.789 TVA'))->toBe('CHE123456789')
        ->and($this->normalizer->normalizeVat(' - '))->toBeNull()
        ->and($this->normalizer->vatVariants('IT12345678901'))->toBe(['IT12345678901', '12345678901'])
        ->and($this->normalizer->vatVariants('12345678901'))->toBe(['12345678901', 'IT12345678901'])
        ->and($this->normalizer->vatVariants('CHE-123.456.789'))->toBe(['CHE123456789', 'CHE123456789MWST', 'CHE123456789TVA', 'CHE123456789IVA'])
        ->and($this->normalizer->vatVariants(null))->toBe([]);
});

test('normalizes company names stripping accents, punctuation and legal suffixes', function (string $input, ?string $expected): void {
    expect($this->normalizer->normalizeCompanyName($input))->toBe($expected);
})->with([
    'spa dotted' => ['Precision Engineering S.p.A.', 'precision engineering'],
    'srl' => ['Rossi & Figli SRL', 'rossi figli'],
    'sagl accents' => ['Società Ticinese Sagl', 'societa ticinese'],
    'gmbh' => ['Muller Bau GmbH', 'muller bau'],
    'ag keeps leading token' => ['AG Holding AG', 'ag holding'],
    'sa' => ['Lugano Trading SA', 'lugano trading'],
    'ltd' => ['Acme Ltd.', 'acme'],
    'single legal token is kept' => ['SRL', 'srl'],
    'blank' => ['   ', null],
]);

test('normalizes emails to lower case', function (): void {
    expect($this->normalizer->normalizeEmail('  Mario.Rossi@Example.IT '))->toBe('mario.rossi@example.it')
        ->and($this->normalizer->normalizeEmail(''))->toBeNull()
        ->and($this->normalizer->normalizeEmail(null))->toBeNull();
});
