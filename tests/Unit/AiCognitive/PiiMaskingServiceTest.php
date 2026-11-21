<?php

declare(strict_types=1);

use Modules\AiCognitive\Infrastructure\Services\PiiMaskingService;

beforeEach(function (): void {
    $this->masking = new PiiMaskingService;
});

test('IBANs with a valid checksum, AHV numbers, Codice Fiscale, emails, phones and VAT numbers are tokenized', function (): void {
    $text = 'Pay CH93 0076 2011 6238 5295 7 or IT60X0542811101000000123456. AHV 756.1234.5678.97, CF RSSMRA85T10A562S, mail anna.rossi@example.ch, tel +41 79 123 45 67, VAT CHE-123.456.789 MWST.';

    $masked = $this->masking->mask($text);

    expect($masked['text'])->not->toContain('CH93 0076')
        ->and($masked['text'])->not->toContain('IT60X05428')
        ->and($masked['text'])->not->toContain('756.1234')
        ->and($masked['text'])->not->toContain('RSSMRA85T10A562S')
        ->and($masked['text'])->not->toContain('anna.rossi@example.ch')
        ->and($masked['text'])->not->toContain('+41 79 123 45 67')
        ->and($masked['text'])->not->toContain('CHE-123.456.789')
        ->and($masked['text'])->toContain('[[IBAN_1]]', '[[IBAN_2]]', '[[AHV_1]]', '[[CF_1]]', '[[EMAIL_1]]', '[[PHONE_1]]', '[[VAT_1]]');
});

test('a number that looks like an IBAN but fails the checksum is left alone', function (): void {
    $masked = $this->masking->mask('Reference CH00 1234 5678 9012 3456 7 stays.');

    expect($masked['map'])->toBe([])
        ->and($masked['text'])->toContain('CH00 1234 5678 9012 3456 7');
});

test('the same value gets the same token and the answer is re-hydrated locally', function (): void {
    $masked = $this->masking->mask('Write to anna@example.ch. Copy to ANNA@example.ch and to bob@example.ch.');

    expect($masked['text'])->toBe('Write to [[EMAIL_1]]. Copy to [[EMAIL_1]] and to [[EMAIL_2]].');

    $answer = $this->masking->rehydrate('Done: [[EMAIL_2]] and [[EMAIL_1]] were notified.', $masked['map']);

    expect($answer)->toBe('Done: bob@example.ch and anna@example.ch were notified.');
});

test('names are masked after a salutation and when the caller knows them', function (): void {
    $masked = $this->masking->mask('Dear Marco Bianchi, thank you. Müller Transport AG delivers on Monday. Cordiali saluti, Giulia Verdi', ['Müller Transport AG']);

    expect($masked['text'])->not->toContain('Marco Bianchi')
        ->and($masked['text'])->not->toContain('Müller Transport AG')
        ->and($masked['text'])->not->toContain('Giulia Verdi')
        ->and($masked['text'])->toContain('delivers on Monday')
        ->and($this->masking->rehydrate($masked['text'], $masked['map']))->toBe('Dear Marco Bianchi, thank you. Müller Transport AG delivers on Monday. Cordiali saluti, Giulia Verdi');
});
