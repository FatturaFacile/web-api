<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;

/**
 * Rebuilds the public demo database from scratch: drops every table, runs the migrations, then seeds the reference
 * data (`erp:bootstrap`) and the demo company (`DemoSeeder`). Scheduled every 30 minutes so that whatever visitors
 * type into the demo does not persist.
 *
 * It destroys all data, so it only runs where `DEMO_RESET_ENABLED=true` (config `app.demo_reset`).
 */
class DemoResetCommand extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Rebuild the demo database from scratch (only where DEMO_RESET_ENABLED=true)';

    public function handle(): int
    {
        if (! config('app.demo_reset')) {
            $this->error('Demo reset is disabled: set DEMO_RESET_ENABLED=true only on the demo environment.');

            return self::FAILURE;
        }

        $steps = [
            ['migrate:fresh', ['--force' => true]],
            ['erp:bootstrap', ['--without-demo' => true, '--force' => true]],
            ['db:seed', ['--class' => DemoSeeder::class, '--force' => true]],
        ];

        foreach ($steps as [$command, $arguments]) {
            if ($this->call($command, $arguments) !== self::SUCCESS) {
                $this->error("Demo reset stopped: {$command} failed.");

                return self::FAILURE;
            }
        }

        $this->info('Demo database rebuilt.');

        return self::SUCCESS;
    }
}
