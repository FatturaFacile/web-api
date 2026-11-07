<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Services\Sales\DocumentNumberingService;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\ProtocolEntry;

/**
 * The protocol register: one sequence per year, taken in the transaction of the registration so that a failure gives
 * the number back and the series keeps no gaps.
 */
final class ProtocolService
{
    private const string Sequence = 'association_protocol';

    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly TenantContext $tenantContext,
        private readonly PrivateFiles $files,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function register(array $data, ?UploadedFile $file): ProtocolEntry
    {
        $registeredOn = CarbonImmutable::parse($data['registered_on'] ?? now());
        $tenantId = (string) $this->tenantContext->getTenantId();

        return DB::transaction(function () use ($data, $file, $registeredOn, $tenantId): ProtocolEntry {
            $sequence = $this->numbering->next(self::Sequence, $registeredOn->year, $tenantId);
            $stored = $file === null ? [] : $this->files->store($file, $tenantId, 'protocol');

            return ProtocolEntry::create([
                ...$data,
                ...$stored,
                'registered_on' => $registeredOn->toDateString(),
                'fiscal_year' => $sequence['fiscal_year'],
                'sequential_number' => $sequence['sequential_number'],
                'number' => sprintf('PROT%d-%05d', $sequence['fiscal_year'], $sequence['sequential_number']),
            ]);
        });
    }

    public function cancel(ProtocolEntry $entry, string $reason): ProtocolEntry
    {
        if ($entry->cancelled_at !== null) {
            throw AssociationException::conflict(__('association_governance.protocol_already_cancelled'));
        }

        $entry->update(['cancelled_at' => now(), 'cancellation_reason' => $reason]);

        return $entry;
    }
}
