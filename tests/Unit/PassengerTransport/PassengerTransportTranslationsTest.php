<?php

declare(strict_types=1);

use Database\Seeders\Translations\EnglishFrontendTranslations;
use Database\Seeders\Translations\FrenchFrontendTranslations;
use Database\Seeders\Translations\GermanFrontendTranslations;
use Database\Seeders\Translations\ItalianFrontendTranslations;
use Database\Seeders\Translations\PassengerTransportFrontendTranslations;
use Modules\PassengerTransport\Domain\Enums\BookingStatus;
use Modules\PassengerTransport\Domain\Enums\BorderCrossing;
use Modules\PassengerTransport\Domain\Enums\DrivingRegime;
use Modules\PassengerTransport\Domain\Enums\ExchangeDirection;
use Modules\PassengerTransport\Domain\Enums\FlightStatus;
use Modules\PassengerTransport\Domain\Enums\ServiceType;
use Modules\PassengerTransport\Domain\Enums\SheetStatus;
use Modules\PassengerTransport\Domain\Enums\VatLineKind;
use Modules\PassengerTransport\Domain\Enums\VehicleCategory;

it('carries every passenger transport key in the four languages with the same placeholders', function (): void {
    $definitions = PassengerTransportFrontendTranslations::definitions();
    expect($definitions)->not->toBeEmpty();

    foreach ($definitions as $key => $tuple) {
        expect($tuple)->toHaveCount(4);

        foreach ($tuple as $text) {
            expect(trim($text))->not->toBe('', $key);
        }

        $placeholders = array_map(static function (string $text): array {
            preg_match_all('/\{\w+}/', $text, $matches);
            sort($matches[0]);

            return $matches[0];
        }, $tuple);

        expect($placeholders[1])->toBe($placeholders[0], $key)
            ->and($placeholders[2])->toBe($placeholders[0], $key)
            ->and($placeholders[3])->toBe($placeholders[0], $key);
    }

    $keys = array_keys($definitions);

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        expect(array_keys(PassengerTransportFrontendTranslations::all($locale)))->toBe($keys);
    }
});

it('does not repeat a key of the base dictionaries', function (): void {
    $own = array_keys(PassengerTransportFrontendTranslations::definitions());

    foreach ([ItalianFrontendTranslations::all(), EnglishFrontendTranslations::all(), GermanFrontendTranslations::all(), FrenchFrontendTranslations::all()] as $dictionary) {
        expect(array_intersect($own, array_keys($dictionary)))->toBe([]);
    }
});

it('has a label for every value the passenger transport API reports', function (): void {
    $keys = array_keys(PassengerTransportFrontendTranslations::definitions());

    $enums = [
        'serviceType' => ServiceType::class,
        'bookingStatus' => BookingStatus::class,
        'flightStatus' => FlightStatus::class,
        'vehicleCategory' => VehicleCategory::class,
        'borderCrossing' => BorderCrossing::class,
        'drivingRegime' => DrivingRegime::class,
        'sheetStatus' => SheetStatus::class,
        'exchangeDirection' => ExchangeDirection::class,
        'vatLineKind' => VatLineKind::class,
    ];

    foreach ($enums as $group => $enum) {
        foreach ($enum::cases() as $case) {
            expect($keys)->toContain("passengerTransport.{$group}.{$case->value}");
        }
    }

    foreach (['confirm', 'start_run', 'arrive', 'board', 'complete', 'cancel'] as $action) {
        expect($keys)->toContain("passengerTransport.bookingAction.{$action}");
    }

    foreach (['cede', 'acquire', 'release'] as $action) {
        expect($keys)->toContain("passengerTransport.exchangeAction.{$action}");
    }

    foreach (['daily_driving_exceeded', 'continuous_driving_exceeded', 'weekly_driving_exceeded'] as $code) {
        expect($keys)->toContain("passengerTransport.infringement.{$code}");
    }
});

it('has a screen text for every error and compliance code of the backend dictionary', function (): void {
    $keys = array_keys(PassengerTransportFrontendTranslations::definitions());
    $backend = require base_path('lang/en/passenger_transport.php');

    foreach (array_keys($backend['errors']) as $code) {
        expect($keys)->toContain("passengerTransport.error.{$code}");
    }

    foreach (array_keys($backend['compliance']) as $code) {
        expect($keys)->toContain("passengerTransport.compliance.{$code}");
    }
});

it('registers the passenger transport dictionary in the frontend translation seeder', function (): void {
    expect(file_get_contents(base_path('database/seeders/FrontendTranslationSeeder.php')))
        ->toContain('PassengerTransportFrontendTranslations::all($locale)');
});
