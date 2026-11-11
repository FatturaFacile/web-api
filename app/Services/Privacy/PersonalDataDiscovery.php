<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use Illuminate\Support\Facades\Schema;

/**
 * Recognises personal-data columns by name. It is the safety net behind the registry: a column that looks personal
 * and is not declared in PersonalDataRegistry is reported by the data map and fails the architecture test.
 */
final class PersonalDataDiscovery
{
    /** Name fragments (whole words between underscores) of personal data columns. */
    private const array Tokens = [
        'email', 'phone', 'mobile', 'first_name', 'last_name', 'full_name', 'birth_date', 'birth_place', 'date_of_birth',
        'fiscal_code', 'tax_code', 'social_security_number', 'ssn', 'iban', 'qr_iban', 'passport', 'street', 'address',
        'ip_address', 'license_plate', 'gender', 'nationality', 'emergency_contact', 'pec',
    ];

    /** Suffixes and prefixes of columns that mention a token without holding personal data (keys, flags, timestamps, rates). */
    private const array IgnoredSuffixes = ['_id', '_at', '_prefix', '_percent', '_token', '_count', '_enabled'];

    private const array IgnoredPrefixes = ['requires_', 'is_', 'has_', 'subject_to_'];

    public static function looksPersonal(string $column): bool
    {
        $name = strtolower($column);

        foreach (self::IgnoredSuffixes as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return false;
            }
        }

        foreach (self::IgnoredPrefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return false;
            }
        }

        foreach (self::Tokens as $token) {
            if (preg_match('/(^|_)'.preg_quote($token, '/').'(_|$)/', $name) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Columns of a table that look personal.
     *
     * @return list<string>
     */
    public function personalColumnsOf(string $table): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return array_values(array_filter(Schema::getColumnListing($table), static fn (string $column): bool => self::looksPersonal($column)));
    }

    /**
     * Tables of the database that look personal and are neither registered nor exempt.
     *
     * @return array<string, list<string>> table => columns
     */
    public function unregisteredTables(PersonalDataRegistry $registry): array
    {
        $known = array_merge(array_keys($registry->entries()), array_keys($registry->exemptions()), array_keys($registry->linkedRecords()));
        $unregistered = [];

        foreach (Schema::getTables() as $table) {
            $name = (string) $table['name'];
            if (in_array($name, $known, true) || in_array($name, ['migrations', 'jobs', 'failed_jobs', 'sessions', 'cache', 'cache_locks', 'password_reset_tokens'], true)) {
                continue;
            }

            $columns = $this->personalColumnsOf($name);
            if ($columns !== []) {
                $unregistered[$name] = $columns;
            }
        }

        ksort($unregistered);

        return $unregistered;
    }
}
