<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Handlers;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\OliveMillAndGrainMill\Application\Commands\TransmitSianCommand;
use Modules\OliveMillAndGrainMill\Application\Contracts\SianTransport;
use Modules\OliveMillAndGrainMill\Domain\Enums\SianStatus;
use Modules\OliveMillAndGrainMill\Domain\Exceptions\OliveMillException;
use Modules\OliveMillAndGrainMill\Domain\Services\SianComplianceService;
use Modules\OliveMillAndGrainMill\Domain\Services\SianFileBuilder;
use Modules\OliveMillAndGrainMill\Events\SianOperationsTransmitted;
use Modules\OliveMillAndGrainMill\Models\SianMovement;

/**
 * Validates the pending operations of the oil register and sends them to the SIAN in one batch, keeping the protocol of the receipt on every
 * operation. The official file can also be exported (XML for the transmission, CSV for the print) without sending it.
 */
final class TransmitSianHandler
{
    public function __construct(
        private readonly SianTransport $transport,
        private readonly SianFileBuilder $builder,
        private readonly SianComplianceService $compliance,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @return array{accepted: bool, protocol: string|null, transmitted: int, skipped: list<array{id: string, progressive: int, code: string}>, message: string|null}
     *
     * @throws OliveMillException nothing_to_transmit
     */
    public function handle(TransmitSianCommand $command): array
    {
        $movements = $this->pending($command);

        if ($movements->isEmpty()) {
            throw OliveMillException::because('nothing_to_transmit');
        }

        [$valid, $skipped] = $this->validate($movements);

        if ($valid->isEmpty()) {
            return ['accepted' => false, 'protocol' => null, 'transmitted' => 0, 'skipped' => $skipped, 'message' => 'no_valid_operation'];
        }

        $cuaa = $this->companyCuaa();
        $receipt = $this->transport->transmit($this->builder->xml($cuaa, $this->rows($valid)), $valid->count());

        DB::transaction(function () use ($valid, $receipt, $cuaa): void {
            foreach ($valid as $movement) {
                $movement->forceFill([
                    'status' => $receipt->accepted ? SianStatus::AckOk : SianStatus::AckError,
                    'sian_protocol' => $receipt->protocol,
                    'transmitted_at' => now(),
                    'payload_snapshot' => $this->builder->xml($cuaa, $this->rows(collect([$movement]))),
                    'error_message' => $receipt->accepted ? null : mb_substr((string) $receipt->message, 0, 500),
                ])->save();
            }
        });

        event(new SianOperationsTransmitted($valid->map(static fn (SianMovement $m): string => (string) $m->id)->values()->all(), $receipt->accepted, $receipt->protocol));

        return ['accepted' => $receipt->accepted, 'protocol' => $receipt->protocol, 'transmitted' => $valid->count(), 'skipped' => $skipped, 'message' => $receipt->message];
    }

    /**
     * The pending operations as an official file; they are marked as exported.
     *
     * @return array{content: string, mime: string, filename: string, count: int}
     *
     * @throws OliveMillException nothing_to_transmit
     */
    public function export(TransmitSianCommand $command, string $format): array
    {
        $movements = $this->pending($command);

        if ($movements->isEmpty()) {
            throw OliveMillException::because('nothing_to_transmit');
        }

        [$valid] = $this->validate($movements);
        $rows = $this->rows($valid);
        $name = 'sian-register-'.now()->format('Ymd-His');

        foreach ($valid as $movement) {
            if (in_array($movement->status, [SianStatus::Draft, SianStatus::Validated], true)) {
                $movement->forceFill(['status' => SianStatus::Exported])->save();
            }
        }

        return $format === 'csv'
            ? ['content' => $this->builder->csv($rows), 'mime' => 'text/csv; charset=UTF-8', 'filename' => $name.'.csv', 'count' => count($rows)]
            : ['content' => $this->builder->xml($this->companyCuaa(), $rows), 'mime' => 'application/xml; charset=UTF-8', 'filename' => $name.'.xml', 'count' => count($rows)];
    }

    /**
     * @return Collection<int, SianMovement>
     */
    private function pending(TransmitSianCommand $command): Collection
    {
        $pending = array_map(static fn (SianStatus $status): string => $status->value, array_filter(SianStatus::cases(), static fn (SianStatus $status): bool => $status->isPending()));

        return SianMovement::query()
            ->with(['fromTank:id,code', 'toTank:id,code'])
            ->whereIn('status', $pending)
            ->when($command->movementIds, static fn (Builder $query, array $ids) => $query->whereIn('id', $ids))
            ->when($command->dateTo, static fn (Builder $query, string $date) => $query->whereDate('operation_date', '<=', $date))
            ->orderBy('progressive')
            ->get();
    }

    /**
     * @param  Collection<int, SianMovement>  $movements
     * @return array{0: Collection<int, SianMovement>, 1: list<array{id: string, progressive: int, code: string}>}
     */
    private function validate(Collection $movements): array
    {
        $valid = collect();
        $skipped = [];

        foreach ($movements as $movement) {
            try {
                $this->compliance->assertCounterparty($movement->causal, $movement->counterparty_cuaa);

                if ((float) $movement->quantity_kg <= 0.0) {
                    throw OliveMillException::because('quantity_must_be_positive');
                }
            } catch (OliveMillException $exception) {
                $skipped[] = ['id' => (string) $movement->id, 'progressive' => $movement->progressive, 'code' => $exception->errorCode()];
                $movement->forceFill(['error_message' => $exception->errorCode()])->save();

                continue;
            }

            if ($movement->status === SianStatus::Draft) {
                $movement->forceFill(['status' => SianStatus::Validated, 'error_message' => null])->save();
            }

            $valid->push($movement);
        }

        return [$valid, $skipped];
    }

    /**
     * @param  Collection<int, SianMovement>  $movements
     * @return list<array<string, scalar|null>>
     */
    private function rows(Collection $movements): array
    {
        return $movements->map(static fn (SianMovement $m): array => [
            'progressive' => $m->progressive,
            'operation_date' => $m->operation_date->toDateString(),
            'causal' => $m->causal->value,
            'from_tank' => $m->fromTank?->code,
            'to_tank' => $m->toTank?->code,
            'counterparty_cuaa' => $m->counterparty_cuaa,
            'quantity_kg' => $m->quantity_kg,
            'quantity_l' => $m->quantity_l,
            'category' => $m->category?->value,
            'origin' => $m->origin_designation?->value,
            'bottle_format' => $m->bottle_format,
            'bottle_count' => $m->bottle_count,
            'package_lot' => $m->package_lot,
            'best_before' => $m->best_before?->toDateString(),
        ])->values()->all();
    }

    private function companyCuaa(): string
    {
        $tenant = Tenant::query()->find($this->tenantContext->getTenantId());

        return (string) ($tenant?->vat_number ?: $tenant?->fiscal_code ?: '');
    }
}
