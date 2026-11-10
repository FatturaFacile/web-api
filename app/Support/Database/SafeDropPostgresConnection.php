<?php

namespace App\Support\Database;

use Illuminate\Database\PostgresConnection;

/**
 * Postgres connection that hands out {@see SafeDropPostgresBuilder} as its schema builder.
 */
class SafeDropPostgresConnection extends PostgresConnection
{
    /**
     * {@inheritDoc}
     */
    public function getSchemaBuilder(): SafeDropPostgresBuilder
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new SafeDropPostgresBuilder($this);
    }
}
