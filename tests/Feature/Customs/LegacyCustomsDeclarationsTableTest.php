<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->legacyTableMigration = require database_path('migrations/2027_03_30_100200_drop_legacy_customs_declarations_table.php');
});

test('the empty legacy table is gone after migrating and the customs goods declarations stay', function (): void {
    expect(Schema::hasTable('customs_declarations'))->toBeFalse()
        ->and(Schema::hasTable('customs_goods_declarations'))->toBeTrue();
});

test('a populated legacy table is kept with a warning, and dropped once emptied', function (): void {
    $this->legacyTableMigration->down();
    $id = (string) Str::uuid();
    DB::table('customs_declarations')->insert(['id' => $id, 'declaration_number' => 'EX-2026-0001', 'declared_value' => '1250.00', 'created_at' => now(), 'updated_at' => now()]);

    Log::spy();
    $this->legacyTableMigration->up();

    expect(Schema::hasTable('customs_declarations'))->toBeTrue()
        ->and(DB::table('customs_declarations')->count())->toBe(1);
    Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool => str_contains($message, 'customs_declarations') && $context === ['rows' => 1]);

    DB::table('customs_declarations')->where('id', $id)->delete();
    $this->legacyTableMigration->up();

    expect(Schema::hasTable('customs_declarations'))->toBeFalse()
        ->and(Schema::hasTable('customs_goods_declarations'))->toBeTrue();
});

test('running the migration twice is harmless', function (): void {
    $this->legacyTableMigration->up();

    expect(Schema::hasTable('customs_declarations'))->toBeFalse();
});
