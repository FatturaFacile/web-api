<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\DigitalPreservation\Application\Commands\CreatePreservationBatchCommand;
use Modules\DigitalPreservation\Application\Commands\DispatchToConservatorCommand;
use Modules\DigitalPreservation\Application\Commands\SealAndTimestampBatchCommand;
use Modules\DigitalPreservation\Application\Handlers\CreatePreservationBatchHandler;
use Modules\DigitalPreservation\Application\Handlers\DispatchToConservatorHandler;
use Modules\DigitalPreservation\Application\Handlers\SealAndTimestampBatchHandler;
use Modules\DigitalPreservation\Domain\Enums\AutoSealFrequency;
use Modules\DigitalPreservation\Domain\Enums\DocumentCategory;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationConfiguration;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationCandidateCollector;
use Throwable;

/**
 * Collects the unpreserved documents of every category up to yesterday into batches, seals and time-stamps them and
 * dispatches them to the conservator of the tenant. One failing category never stops the others.
 */
final class AutoIngestPreservationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  ?string  $tenantId  one tenant, or every tenant with an active configuration
     * @param  bool  $force  ignore the schedule frequency of the configuration
     */
    public function __construct(public readonly ?string $tenantId = null, public readonly bool $force = false) {}

    public function handle(TenantContext $context, PreservationCandidateCollector $collector, CreatePreservationBatchHandler $create, SealAndTimestampBatchHandler $seal, DispatchToConservatorHandler $dispatch): void
    {
        $configurations = PreservationConfiguration::withoutGlobalScopes()->where('is_active', true)
            ->when($this->tenantId, fn ($q, $id) => $q->where('tenant_id', $id))->get();

        foreach ($configurations as $configuration) {
            if (! $this->force && ! $this->isDue($configuration->auto_seal_frequency)) {
                continue;
            }

            $tenant = Tenant::find($configuration->tenant_id);

            if ($tenant === null) {
                continue;
            }

            $context->runWithTenant($tenant, function () use ($collector, $create, $seal, $dispatch, $tenant): void {
                $to = Carbon::yesterday()->endOfDay();

                foreach (DocumentCategory::cases() as $category) {
                    try {
                        $pending = $collector->collect($category, Carbon::create(2000, 1, 1), $to);

                        if ($pending->isEmpty()) {
                            continue;
                        }

                        $dates = $pending->pluck('documentDate')->filter()->sort()->values();
                        $from = Carbon::parse((string) ($dates->first() ?? $to->toDateString()));
                        $batch = $create->handle(new CreatePreservationBatchCommand($category, $from, $to));
                        $batch = $seal->handle(new SealAndTimestampBatchCommand($batch->id));
                        $dispatch->handle(new DispatchToConservatorCommand($batch->id));
                    } catch (Throwable $exception) {
                        Log::warning('Preservation auto-ingest failed', ['tenant_id' => $tenant->id, 'category' => $category->value, 'error' => $exception->getMessage()]);
                    }
                }
            });
        }
    }

    private function isDue(AutoSealFrequency $frequency): bool
    {
        return match ($frequency) {
            AutoSealFrequency::Daily => true,
            AutoSealFrequency::Weekly => now()->isMonday(),
            AutoSealFrequency::Monthly => now()->day === 1,
        };
    }
}
