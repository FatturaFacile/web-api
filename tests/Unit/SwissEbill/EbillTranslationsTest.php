<?php

declare(strict_types=1);

use Database\Seeders\FrontendTranslationSeeder;
use Database\Seeders\Translations\EbillFrontendTranslations;
use Database\Seeders\Translations\EnglishFrontendTranslations;
use Database\Seeders\Translations\FrenchFrontendTranslations;
use Database\Seeders\Translations\GermanFrontendTranslations;
use Database\Seeders\Translations\ItalianFrontendTranslations;
use Illuminate\Support\Arr;
use Modules\SwissEbill\Domain\Enums\EbillChannelType;
use Modules\SwissEbill\Domain\Enums\EbillConnectionStatus;
use Modules\SwissEbill\Domain\Enums\EbillEventSource;
use Modules\SwissEbill\Domain\Enums\EbillParticipantStatus;
use Modules\SwissEbill\Domain\Enums\EbillProtocol;
use Modules\SwissEbill\Domain\Enums\EbillProvider;
use Modules\SwissEbill\Domain\Enums\EbillRejectionCode;
use Modules\SwissEbill\Domain\Enums\EbillTransmissionStatus;

/**
 * The texts of the eBill screens live in the translation seeder only: every key has the four languages (Italian serves it-CH and it-IT,
 * German de-CH, French fr-CH, English en), the placeholders agree, every enum code, rejection reason and error code has a label.
 */
test('every key has a text in all four languages with the same placeholders', function (): void {
    foreach (EbillFrontendTranslations::definitions() as $key => $tuple) {
        expect($tuple)->toHaveCount(4);

        $placeholders = null;

        foreach ($tuple as $text) {
            expect(trim($text))->not->toBe('', $key);
            preg_match_all('/\{\{\s*\w+\s*\}\}/', $text, $found);
            $current = array_map(static fn (string $p): string => preg_replace('/\s+/', '', $p), $found[0]);
            sort($current);
            $placeholders ??= $current;

            expect($current)->toBe($placeholders, $key);
        }
    }
});

test('every enum code has a label', function (): void {
    $keys = array_keys(EbillFrontendTranslations::definitions());

    $expected = [];

    foreach (EbillTransmissionStatus::cases() as $case) {
        $expected[] = 'swissEbill.status.'.$case->value;
        $expected[] = 'swissEbill.statusHint.'.$case->value;
        $expected[] = 'swissEbill.event.'.$case->value;
    }

    foreach (EbillParticipantStatus::cases() as $case) {
        $expected[] = 'swissEbill.participant.'.$case->value;
    }

    foreach (EbillChannelType::cases() as $case) {
        $expected[] = 'swissEbill.channel.'.$case->value;
    }

    foreach (EbillProvider::cases() as $case) {
        $expected[] = 'swissEbill.provider.'.$case->value;
    }

    foreach (EbillProtocol::cases() as $case) {
        $expected[] = 'swissEbill.protocol.'.$case->value;
    }

    foreach (EbillConnectionStatus::cases() as $case) {
        $expected[] = 'swissEbill.connection.'.$case->value;
    }

    foreach (EbillEventSource::cases() as $case) {
        $expected[] = 'swissEbill.source.'.$case->value;
    }

    foreach (EbillRejectionCode::cases() as $case) {
        $expected[] = 'swissEbill.rejection.'.$case->value.'.title';
        $expected[] = 'swissEbill.rejection.'.$case->value.'.hint';
    }

    expect(array_values(array_diff($expected, $keys)))->toBe([]);
});

test('every error and violation code of the backend has a label in the client and in the API', function (): void {
    $keys = array_keys(EbillFrontendTranslations::definitions());
    $apiErrors = array_keys((require lang_path('en/swiss_ebill.php'))['errors']);

    $missingInClient = array_values(array_filter($apiErrors, static fn (string $code): bool => ! in_array('swissEbill.error.'.$code, $keys, true)));
    $violations = ['ebill_disabled', 'biller_id_missing', 'biller_id_invalid', 'invoice_not_issued', 'currency_unsupported', 'due_date_missing', 'lines_missing', 'vat_rate_unsupported', 'biller_uid_missing', 'biller_uid_invalid', 'biller_iban_missing', 'qr_bill_invalid', 'recipient_missing', 'recipient_ebill_id_missing', 'recipient_not_enrolled', 'recipient_uid_missing', 'recipient_name_missing', 'recipient_address_missing', 'payload_too_large', 'xml_malformed', 'xsd_invalid'];
    $missingViolations = array_values(array_filter($violations, static fn (string $code): bool => ! in_array('swissEbill.violation.'.$code, $keys, true)));

    // The webhook-only codes (signature, slug, body) are for the gateway, not for a screen.
    expect(array_diff($missingInClient, ['invalid_signature', 'unknown_webhook', 'unknown_transaction', 'payload_unreadable']))->toBe([])
        ->and($missingViolations)->toBe([]);
});

test('the API dictionaries have the same keys in all four languages', function (): void {
    $reference = array_keys(Arr::dot(require lang_path('en/swiss_ebill.php')));
    sort($reference);

    foreach (['it', 'de', 'fr'] as $locale) {
        $keys = array_keys(Arr::dot(require lang_path("{$locale}/swiss_ebill.php")));
        sort($keys);

        expect($keys)->toBe($reference, $locale);
    }
});

test('no key is also defined by the base frontend dictionaries and all of them reach the served dictionary', function (): void {
    $own = array_keys(EbillFrontendTranslations::definitions());
    $bases = [
        'it' => ItalianFrontendTranslations::all(),
        'en' => EnglishFrontendTranslations::all(),
        'de' => GermanFrontendTranslations::all(),
        'fr' => FrenchFrontendTranslations::all(),
    ];

    foreach ($bases as $locale => $base) {
        $served = FrontendTranslationSeeder::dictionary($locale);

        expect(array_values(array_intersect($own, array_keys($base))))->toBe([], $locale);

        foreach ($own as $key) {
            expect(array_key_exists($key, $served))->toBeTrue($locale.' '.$key);
        }
    }

    expect(count($own))->toBe(count(array_unique($own)));
});
