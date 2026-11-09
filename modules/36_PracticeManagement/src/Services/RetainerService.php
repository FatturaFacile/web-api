<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\FeeNote;
use App\Models\LegalCase;
use App\Services\Tenancy\TenantClock;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\PracticeManagement\Models\Retainer;
use Modules\PracticeManagement\Models\RetainerApplication;

/**
 * Retainers of a professional firm: advance payments received from a client and their application to the client's fee
 * notes. A retainer is applied at most up to what is left of it and up to what the fee note still asks; amounts are
 * decimal strings computed with bcmath. The register carries no ledger posting: the bank receipt posts through Treasury.
 */
final class RetainerService
{
    public function __construct(private readonly TenantClock $clock) {}

    /**
     * Registers a retainer received from a client, for the whole client or for one of its cases.
     *
     * @param  array{client_id: string, legal_case_id?: ?string, received_on: string, amount: string, reference?: ?string, notes?: ?string}  $data
     */
    public function record(array $data, ?string $userId): Retainer
    {
        if (($data['legal_case_id'] ?? null) !== null && (string) LegalCase::query()->findOrFail($data['legal_case_id'])->client_id !== (string) $data['client_id']) {
            throw new BusinessRuleException('The case belongs to another client.');
        }

        $retainer = new Retainer;
        $retainer->fill([...$data, 'applied_amount' => '0.00', 'status' => 'open', 'recorded_by' => $userId]);
        $retainer->save();

        return $retainer;
    }

    /**
     * Applies part of a retainer to a fee note of the same client that is not paid yet. The application cannot exceed the
     * remaining retainer nor the amount the fee note still asks.
     */
    public function apply(Retainer $retainer, string $feeNoteId, string $amount, ?string $userId): RetainerApplication
    {
        return DB::transaction(function () use ($retainer, $feeNoteId, $amount, $userId): RetainerApplication {
            $locked = Retainer::query()->lockForUpdate()->findOrFail($retainer->id);
            $note = FeeNote::query()->lockForUpdate()->findOrFail($feeNoteId);

            if ($locked->status !== 'open') {
                throw new BusinessRuleException('Only an open retainer can be applied.');
            }

            if ((string) $note->client_id !== (string) $locked->client_id) {
                throw new BusinessRuleException('The fee note belongs to another client.');
            }

            if ($locked->legal_case_id !== null && (string) $note->legal_case_id !== (string) $locked->legal_case_id) {
                throw new BusinessRuleException('The retainer is for a case: apply it to a fee note of that case.');
            }

            if ($note->status === 'paid') {
                throw new BusinessRuleException('The fee note is already paid.');
            }

            $remaining = Decimal::sub((string) $locked->amount, (string) $locked->applied_amount);
            $asked = $this->outstanding($note);

            if (Decimal::compare($amount, $remaining) > 0) {
                throw new BusinessRuleException('The retainer has only '.Decimal::round2($remaining).' left.');
            }

            if (Decimal::compare($amount, $asked) > 0) {
                throw new BusinessRuleException('The fee note asks only '.Decimal::round2($asked).' more.');
            }

            $application = new RetainerApplication;
            $application->fill(['retainer_id' => $locked->id, 'fee_note_id' => $note->id, 'amount' => $amount, 'applied_on' => $this->clock->today()->toDateString(), 'applied_by' => $userId]);
            $application->save();

            $applied = Decimal::add((string) $locked->applied_amount, $amount);
            $locked->update(['applied_amount' => $applied, 'status' => Decimal::compare($applied, (string) $locked->amount) === 0 ? 'exhausted' : 'open']);

            return $application;
        });
    }

    /**
     * Voids a retainer entered by mistake, while nothing of it has been applied.
     */
    public function void(Retainer $retainer): Retainer
    {
        return DB::transaction(function () use ($retainer): Retainer {
            $locked = Retainer::query()->lockForUpdate()->findOrFail($retainer->id);

            if ($locked->status === 'void' || Decimal::isPositive((string) $locked->applied_amount)) {
                throw new BusinessRuleException('Only a retainer with nothing applied can be voided.');
            }

            $locked->update(['status' => 'void']);

            return $locked;
        });
    }

    /**
     * Per client: retainers received, applied and still available, void ones excluded.
     *
     * @return list<array{client_id: string, received: string, applied: string, available: string}>
     */
    public function balances(): array
    {
        return Retainer::query()->where('status', '!=', 'void')->get()->groupBy('client_id')->map(static function ($group, $clientId): array {
            $received = (string) $group->reduce(static fn (string $sum, Retainer $row): string => Decimal::add($sum, (string) $row->amount), '0');
            $applied = (string) $group->reduce(static fn (string $sum, Retainer $row): string => Decimal::add($sum, (string) $row->applied_amount), '0');

            return ['client_id' => (string) $clientId, 'received' => Decimal::round2($received), 'applied' => Decimal::round2($applied), 'available' => Decimal::round2(Decimal::sub($received, $applied))];
        })->sortKeys()->values()->all();
    }

    /**
     * What a fee note still asks after the retainers applied to it.
     *
     * @return array{net_payable: string, retainers_applied: string, outstanding: string, applications: list<array<string, string>>}
     */
    public function feeNoteSummary(FeeNote $note): array
    {
        $applications = RetainerApplication::query()->where('fee_note_id', $note->id)->orderBy('applied_on')->get();

        return [
            'net_payable' => Decimal::round2((string) $note->net_payable),
            'retainers_applied' => Decimal::round2($this->applied($note)),
            'outstanding' => Decimal::round2($this->outstanding($note)),
            'applications' => $applications->map(static fn (RetainerApplication $row): array => ['retainer_id' => $row->retainer_id, 'amount' => (string) $row->amount, 'applied_on' => $row->applied_on->toDateString()])->all(),
        ];
    }

    private function applied(FeeNote $note): string
    {
        return (string) RetainerApplication::query()->where('fee_note_id', $note->id)->get()
            ->reduce(static fn (string $sum, RetainerApplication $row): string => Decimal::add($sum, (string) $row->amount), '0');
    }

    private function outstanding(FeeNote $note): string
    {
        return Decimal::sub((string) $note->net_payable, $this->applied($note));
    }
}
