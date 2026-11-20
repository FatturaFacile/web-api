<?php

declare(strict_types=1);

namespace Modules\Forecasting\Infrastructure\Console;

use App\Models\Tenant;
use Closure;
use Illuminate\Console\Command;
use Modules\Forecasting\Domain\Exceptions\ForecastingException;
use Throwable;

/**
 * Base of the nightly forecasting commands: runs a step for every active tenant (or the one named by --tenant) and keeps
 * going when a tenant fails, so one bad dataset does not stop the others.
 */
abstract class ForecastingTenantCommand extends Command
{
    /**
     * @param  Closure(string): string  $step  runs the step for a tenant id and returns a one-line result
     */
    protected function forEachTenant(Closure $step): int
    {
        $ids = $this->option('tenant')
            ? [(string) $this->option('tenant')]
            : Tenant::query()->where('status', 'active')->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $failed = 0;

        foreach ($ids as $id) {
            try {
                $this->line("{$id}: ".$step($id));
            } catch (ForecastingException $exception) {
                $failed++;
                $this->warn("{$id}: ".$exception->getMessage());
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
                $this->error("{$id}: ".$exception->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
