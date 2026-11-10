<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\PostgresBuilder;

/**
 * Postgres schema builder whose dropAllTables() drops in chunks of 20 tables, so a schema with thousands of tables does
 * not exceed the shared lock table (max_locks_per_transaction) in a single DROP statement.
 */
class SafeDropPostgresBuilder extends PostgresBuilder
{
    /**
     * {@inheritDoc}
     */
    public function dropAllTables(): void
    {
        $tables = [];
        $excludedTables = $this->connection->getConfig('dont_drop') ?? ['spatial_ref_sys'];

        foreach ($this->getTables($this->getCurrentSchemaListing()) as $table) {
            if (empty(array_intersect([$table['name'], $table['schema_qualified_name']], $excludedTables))) {
                $tables[] = $table['schema_qualified_name'];
            }
        }

        if (empty($tables)) {
            return;
        }

        foreach (array_chunk($tables, 20) as $chunk) {
            $wrapped = array_map(static fn (string $table): string => '"'.str_replace(['"', '.'], ['""', '"."'], $table).'"', $chunk);
            $this->connection->statement('drop table if exists '.implode(',', $wrapped).' cascade');
        }
    }
}
