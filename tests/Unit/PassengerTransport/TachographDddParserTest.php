<?php

declare(strict_types=1);

use Modules\PassengerTransport\Domain\Enums\DddFileType;
use Modules\PassengerTransport\Domain\Support\TachographDddParser;
use Modules\PassengerTransport\Exceptions\PassengerTransportException;

require_once __DIR__.'/DddFixtures.php';

it('reads the card, its expiry and the driving of each day of a driver card file', function (): void {
    $file = ptrDriverCardFile([
        '2028-03-06' => [[PTR_REST, 0], [PTR_DRIVING, 360], [PTR_REST, 720], [PTR_DRIVING, 780], [PTR_REST, 1100]],
        '2028-03-07' => [[PTR_REST, 0], [PTR_DRIVING, 480], [PTR_REST, 600], [PTR_DRIVING, 660], [PTR_REST, 900]],
    ]);

    $parsed = (new TachographDddParser)->parse($file);

    expect($parsed['type'])->toBe(DddFileType::DriverCard)
        ->and($parsed['card_number'])->toBe('1000012345678901')
        ->and($parsed['card_expires_on'])->toBe('2029-01-01')
        ->and($parsed['period_start'])->toBe('2028-03-06')
        ->and($parsed['period_end'])->toBe('2028-03-07')
        ->and($parsed['days'])->toHaveCount(2)
        ->and($parsed['days'][0]['driving_minutes'])->toBe(680)
        ->and($parsed['days'][1]['driving_minutes'])->toBe(360)
        ->and($parsed['days'][1]['longest_rest_minutes'])->toBe(540)
        ->and($parsed['days'][1]['max_continuous_driving_minutes'])->toBe(240)
        ->and($parsed['days'][1]['continuous_driving_minutes_at_end'])->toBe(0);
});

it('flags the days that exceed the daily and the continuous driving limits', function (): void {
    $file = ptrDriverCardFile([
        '2028-03-06' => [[PTR_REST, 0], [PTR_DRIVING, 360], [PTR_REST, 720], [PTR_DRIVING, 780], [PTR_REST, 1100]],
        '2028-03-07' => [[PTR_REST, 0], [PTR_DRIVING, 480], [PTR_REST, 600], [PTR_DRIVING, 660], [PTR_REST, 900]],
    ]);

    $infringements = (new TachographDddParser)->parse($file)['infringements'];

    expect($infringements)->toBe([
        ['code' => 'daily_driving_exceeded', 'date' => '2028-03-06', 'minutes' => 680],
        ['code' => 'continuous_driving_exceeded', 'date' => '2028-03-06', 'minutes' => 360],
    ]);
});

it('treats 15 plus 30 minutes of break as the split 45 minute break', function (): void {
    // 200 min driving, 15 min break, 50 min driving, 30 min break: the two breaks make the 45 minutes, the clock restarts.
    $split = ptrDriverCardFile(['2028-03-06' => [[PTR_DRIVING, 0], [PTR_REST, 200], [PTR_DRIVING, 215], [PTR_REST, 265], [PTR_DRIVING, 295], [PTR_REST, 395]]]);
    // The second break lasts 20 min only: the driving goes on to 350 min.
    $short = ptrDriverCardFile(['2028-03-06' => [[PTR_DRIVING, 0], [PTR_REST, 200], [PTR_DRIVING, 215], [PTR_REST, 265], [PTR_DRIVING, 285], [PTR_REST, 385]]]);

    $parser = new TachographDddParser;

    expect($parser->parse($split)['infringements'])->toBe([])
        ->and($parser->parse($split)['days'][0]['max_continuous_driving_minutes'])->toBe(250)
        ->and(array_column($parser->parse($short)['infringements'], 'code'))->toBe(['continuous_driving_exceeded'])
        ->and($parser->parse($short)['days'][0]['max_continuous_driving_minutes'])->toBe(350);
});

it('flags a week above 56 hours of driving', function (): void {
    $days = [];

    foreach (['2028-03-06', '2028-03-07', '2028-03-08', '2028-03-09', '2028-03-10', '2028-03-11'] as $date) {
        // 270 + 300 minutes a day, six days: 57 hours.
        $days[$date] = [[PTR_REST, 0], [PTR_DRIVING, 300], [PTR_REST, 570], [PTR_DRIVING, 600], [PTR_REST, 900]];
    }

    $codes = array_column((new TachographDddParser)->parse(ptrDriverCardFile($days))['infringements'], 'code');

    expect($codes)->toContain('weekly_driving_exceeded');
});

it('recognises a vehicle unit file and archives it without reading the activities', function (): void {
    $parsed = (new TachographDddParser)->parse("\x76\x01".str_repeat("\0", 64));

    expect($parsed['type'])->toBe(DddFileType::VehicleUnit)
        ->and($parsed['card_number'])->toBeNull()
        ->and($parsed['days'])->toBe([])
        ->and($parsed['infringements'])->toBe([]);
});

it('refuses an empty file and a file without the identification and activity records', function (): void {
    $parser = new TachographDddParser;

    expect(fn () => $parser->parse('abc'))->toThrow(PassengerTransportException::class)
        ->and(fn () => $parser->parse(ptrEf(0x0002, str_repeat("\0", 25))))->toThrow(PassengerTransportException::class);

    try {
        $parser->parse(ptrEf(0x0002, str_repeat("\0", 25)));
    } catch (PassengerTransportException $exception) {
        expect($exception->errorCode())->toBe('ddd_file_unrecognised');
    }
});

it('stops at a truncated file instead of reading past its end', function (): void {
    $file = ptrDriverCardFile(['2028-03-06' => [[PTR_REST, 0], [PTR_DRIVING, 360], [PTR_REST, 720]]]);

    expect(fn () => (new TachographDddParser)->parse(substr($file, 0, 120)))->toThrow(PassengerTransportException::class);
});
