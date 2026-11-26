<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Treasury\Currency\Application\Commands\ReverseFxRevaluationCommand;
use Modules\Treasury\Currency\Application\Handlers\ReverseFxRevaluationHandler;
use Modules\Treasury\Currency\Domain\Enums\RevaluationStatus;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencyFxRevaluation;
use Throwable;

/**
 * Reverses every posted revaluation whose reversal day (the first day of the next period) has come. A revaluation that fails is
 * logged and left posted for the next run; the others go on. Running twice reverses nothing twice: the handler is idempotent.
 */
final class ReverseRevaluationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly ?string $today = null) {}

    public function handle(ReverseFxRevaluationHandler $handler, TenantContext $tenants): int
    {
        $today = CarbonImmutable::parse($this->today ?? 'today')->toDateString();
        $due = CurrencyFxRevaluation::query()->withoutGlobalScopes()
            ->where('status', RevaluationStatus::Posted->value)
            ->whereNull('deleted_at')
            ->whereDate('reverse_on', '<=', $today)
            ->orderBy('reverse_on')
            ->get(['id', 'tenant_id', 'reverse_on']);

        $reversed = 0;

        foreach ($due as $revaluation) {
            $tenant = Tenant::query()->find($revaluation->tenant_id);

            if ($tenant === null) {
                continue;
            }

            try {
                $tenants->runWithTenant($tenant, static function () use ($handler, $revaluation): void {
                    $handler->handle(new ReverseFxRevaluationCommand((string) $revaluation->tenant_id, (string) $revaluation->id));
                });
                $reversed++;
            } catch (Throwable $exception) {
                Log::warning('FX revaluation not reversed.', ['revaluation' => $revaluation->id, 'reason' => $exception->getMessage()]);
            }
        }

        return $reversed;
    }
}
