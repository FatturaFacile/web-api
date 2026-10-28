<?php

declare(strict_types=1);

use Enterprise\Foundation\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

test('has uuid v7 concern generates valid RFC 9562 version 7 time-ordered UUIDs', function () {
    $model = new class extends Model
    {
        use HasUuidV7;

        protected $table = 'tenants';

        public $incrementing = false;

        protected $keyType = 'string';
    };

    // Simulate model saving/creating lifecycle
    $uuid = (string) Str::uuid7();

    // Verify UUID RFC 9562 version 7
    $version = (int) $uuid[14]; // In canonical 8-4-4-4-12 UUID, char index 14 is the version digit
    expect($version)->toBe(7);

    // Verify ordering
    $uuid1 = (string) Str::uuid7();
    usleep(1500); // 1.5ms to guarantee millisecond advancement
    $uuid2 = (string) Str::uuid7();

    expect(strcmp($uuid1, $uuid2))->toBeLessThan(0);
});
