<?php

declare(strict_types=1);

use Modules\Observability\Privacy\PiiScrubber;

it('masks personal and fiscal identifiers inside free text', function (string $input, string $token, string $secret): void {
    $clean = (new PiiScrubber)->scrubString('Paid by '.$input.' today');

    expect($clean)->toContain($token)
        ->and($clean)->not->toContain($secret);
})->with([
    'swiss iban' => ['CH9300762011623852957', '[IBAN]', 'CH9300762011623852957'],
    'italian iban with spaces' => ['IT60 X054 2811 1010 0000 0123 456', '[IBAN]', '0123 456'],
    'avs number' => ['756.1234.5678.97', '[AVS]', '756.1234.5678.97'],
    'codice fiscale' => ['RSSMRA85T10A562S', '[CF]', 'RSSMRA85T10A562S'],
    'uid number' => ['CHE-123.456.788 MWST', '[UID]', '123.456.788'],
    'italian vat number' => ['IT01234567890', '[VAT]', '01234567890'],
    'german vat number' => ['DE123456789', '[VAT]', '123456789'],
    'email' => ['mario.rossi@example.com', '[EMAIL]', 'mario.rossi'],
    'phone' => ['+41 79 123 45 67', '[PHONE]', '123 45 67'],
    'bearer token' => ['Bearer abcdefghijklmnop12345', 'Bearer [TOKEN]', 'abcdefghijklmnop12345'],
]);

it('leaves harmless text untouched', function (): void {
    $text = 'Invoice 2026/0042 posted in 35 ms for tenant 0192f3a0-aaaa-7bbb-8ccc-123456789abc';

    expect((new PiiScrubber)->scrubString($text))->toBe($text);
});

it('redacts the values of sensitive keys at any depth and scrubs the other strings', function (): void {
    $clean = (new PiiScrubber)->scrubArray([
        'tenant_id' => 'abc',
        'amount' => 12.5,
        'iban' => 'CH9300762011623852957',
        'password' => 'hunter2',
        'customer' => ['first_name' => 'Mario', 'note' => 'write to a@b.ch', 'city_code' => 'ZH'],
        'headers' => ['Authorization' => 'Bearer abcdefghijklmnop12345'],
        'empty' => '',
        'nothing' => null,
    ]);

    expect($clean['tenant_id'])->toBe('abc')
        ->and($clean['amount'])->toBe(12.5)
        ->and($clean['iban'])->toBe(PiiScrubber::MASK)
        ->and($clean['password'])->toBe(PiiScrubber::MASK)
        ->and($clean['customer']['first_name'])->toBe(PiiScrubber::MASK)
        ->and($clean['customer']['note'])->toBe('write to [EMAIL]')
        ->and($clean['customer']['city_code'])->toBe('ZH')
        ->and($clean['headers']['Authorization'])->toBe(PiiScrubber::MASK)
        ->and($clean['empty'])->toBe('')
        ->and($clean['nothing'])->toBeNull();
});

it('recognises sensitive keys', function (string $key, bool $sensitive): void {
    expect((new PiiScrubber)->isSensitiveKey($key))->toBe($sensitive);
})->with([
    ['api_key', true], ['access_token', true], ['codice_fiscale', true], ['vat_number', true], ['company_name', true], ['name', true],
    ['street', true], ['phone', true], ['billing_address', true], ['ip_address', true], ['server.address', false], ['job.name', false], ['status', false], ['tenant_id', false], ['trace_id', false], ['vat_rate', false], ['amount', false],
]);

it('flattens objects and exceptions without keeping their arguments', function (): void {
    $scrubber = new PiiScrubber;
    $clean = $scrubber->scrubArray([
        'error' => new RuntimeException('Failed for mario@example.com'),
        'model' => new ArrayObject(['secret' => 'x']),
    ], true);

    expect($clean['error']['class'])->toBe(RuntimeException::class)
        ->and($clean['error']['message'])->toBe('Failed for [EMAIL]')
        ->and($clean['error']['trace'])->toBeArray()
        ->and($clean['model'])->toBe('[object ArrayObject]');
});

it('keeps exception objects as they are when not flattening', function (): void {
    $exception = new RuntimeException('x');

    expect((new PiiScrubber)->scrubArray(['exception' => $exception])['exception'])->toBe($exception);
});

it('stops at a maximum depth', function (): void {
    $nested = ['v' => 'x'];

    for ($i = 0; $i < 12; $i++) {
        $nested = ['child' => $nested];
    }

    expect(json_encode((new PiiScrubber)->scrubArray($nested)))->toContain('[TRUNCATED]');
});
