<?php

declare(strict_types=1);

use App\Services\Privacy\PersonalDataDiscovery;
use App\Services\Privacy\PersonalDataRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;

uses(RefreshDatabase::class);

/**
 * Every Eloquent model of the application (app/Models and modules/ * /src/Models), as class => table.
 *
 * @return array<class-string<Model>, string>
 */
function privacyArchModels(): array
{
    $models = [];
    $finder = (new Finder)->files()->name('*.php')->in(array_filter([app_path('Models'), ...glob(base_path('modules/*/src/Models')) ?: []], 'is_dir'));

    foreach ($finder as $file) {
        $source = $file->getContents();
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1 || preg_match('/^(?:abstract\s+|final\s+)*class\s+(\w+)/m', $source, $class) !== 1) {
            continue;
        }

        $name = $namespace[1].'\\'.$class[1];
        if (! class_exists($name)) {
            continue;
        }

        $reflection = new ReflectionClass($name);
        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        $models[$name] = (new $name)->getTable();
    }

    return $models;
}

test('every model with personal-looking columns is declared in the personal data registry or exempted with a reason', function (): void {
    $registry = new PersonalDataRegistry;
    $discovery = new PersonalDataDiscovery;
    $known = array_merge(array_keys($registry->entries()), array_keys($registry->exemptions()));
    $unregistered = [];

    foreach (privacyArchModels() as $class => $table) {
        if (! Schema::hasTable($table) || in_array($table, $known, true)) {
            continue;
        }

        $columns = $discovery->personalColumnsOf($table);
        if ($columns !== []) {
            $unregistered[] = "{$class} ({$table}: ".implode(', ', $columns).')';
        }
    }

    expect($unregistered)->toBe([], 'Declare these models in App\\Services\\Privacy\\PersonalDataRegistry (entries or exemptions): '.implode('; ', $unregistered));
});

test('the personal columns of every registered table are declared completely and exist', function (): void {
    $registry = new PersonalDataRegistry;
    $discovery = new PersonalDataDiscovery;
    $problems = [];

    foreach ($registry->entries() as $entry) {
        if (! Schema::hasTable($entry->table)) {
            $problems[] = "{$entry->table}: table does not exist";

            continue;
        }

        foreach ($entry->personalColumns as $column) {
            if (! Schema::hasColumn($entry->table, $column)) {
                $problems[] = "{$entry->table}.{$column}: declared column does not exist";
            }
        }

        foreach (array_diff($discovery->personalColumnsOf($entry->table), $entry->personalColumns) as $column) {
            $problems[] = "{$entry->table}.{$column}: personal-looking column missing from the registry entry";
        }

        foreach ($entry->subjectLinks as $subject => $column) {
            if (! in_array($subject, PersonalDataRegistry::subjectTypes(), true) || ! Schema::hasColumn($entry->table, $column)) {
                $problems[] = "{$entry->table}: invalid subject link {$subject} => {$column}";
            }
        }

        if ($entry->ageColumn !== null && ! Schema::hasColumn($entry->table, $entry->ageColumn)) {
            $problems[] = "{$entry->table}: age column {$entry->ageColumn} does not exist";
        }

        if ($entry->hasTenantColumn && ! Schema::hasColumn($entry->table, 'tenant_id')) {
            $problems[] = "{$entry->table}: flagged as tenant scoped but has no tenant_id";
        }

        if (! $entry->hasTenantColumn && $entry->tenantParent === null) {
            $problems[] = "{$entry->table}: no tenant_id and no tenant parent: the rows cannot be told apart by company";
        }

        foreach ($entry->models as $model) {
            if (! class_exists($model) || (new $model)->getTable() !== $entry->table) {
                $problems[] = "{$entry->table}: model {$model} does not map this table";
            }
        }
    }

    foreach ($registry->linkedRecords() as $table => $link) {
        if (! Schema::hasTable($table)) {
            $problems[] = "{$table}: linked table does not exist";

            continue;
        }

        foreach ($link['links'] as $subject => $column) {
            if (! Schema::hasColumn($table, $column)) {
                $problems[] = "{$table}: link column {$column} ({$subject}) does not exist";
            }
        }

        if ($link['hasTenantColumn'] && ! Schema::hasColumn($table, 'tenant_id')) {
            $problems[] = "{$table}: flagged as tenant scoped but has no tenant_id";
        }
    }

    expect($problems)->toBe([]);
});

test('the discovery recognises personal columns and ignores keys, flags and timestamps', function (): void {
    expect(PersonalDataDiscovery::looksPersonal('email'))->toBeTrue()
        ->and(PersonalDataDiscovery::looksPersonal('guest_phone'))->toBeTrue()
        ->and(PersonalDataDiscovery::looksPersonal('billing_address'))->toBeTrue()
        ->and(PersonalDataDiscovery::looksPersonal('tax_code_id'))->toBeFalse()
        ->and(PersonalDataDiscovery::looksPersonal('email_verified_at'))->toBeFalse()
        ->and(PersonalDataDiscovery::looksPersonal('requires_iban'))->toBeFalse()
        ->and(PersonalDataDiscovery::looksPersonal('phone_prefix'))->toBeFalse()
        ->and(PersonalDataDiscovery::looksPersonal('total_gross'))->toBeFalse();
});

test('the registry never allows a retention policy on accounting, payroll or evidence data', function (): void {
    $registry = new PersonalDataRegistry;

    foreach (['invoices', 'payroll_payslips', 'hr_employees', 'crm_consents', 'audit_logs'] as $key) {
        expect($registry->findOrFail($key)->supportsRetentionPolicy())->toBeFalse($key);
    }

    expect($registry->findOrFail('crm_leads')->supportsRetentionPolicy())->toBeTrue();
});
