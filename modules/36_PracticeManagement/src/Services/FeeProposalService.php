<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Services;

use App\Contracts\PdfGenerator;
use App\Exceptions\BusinessRuleException;
use App\Models\LegalCase;
use App\Services\Tenancy\TenantClock;
use App\Services\Tenancy\TenantContext;
use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\PracticeManagement\Models\PracticeCaseParty;
use Modules\PracticeManagement\Models\PracticeConflictCheck;
use Modules\PracticeManagement\Models\PracticeFeeProposal;

/**
 * Fee proposals: draft, sent, then accepted (which opens the matter), declined or expired once the validity date passes. Accepting needs a conflicts check of
 * the client: a "conflict" result blocks the acceptance unless a reason for proceeding is given.
 */
final class FeeProposalService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PracticeFeeProposal
    {
        return DB::transaction(function () use ($data): PracticeFeeProposal {
            TransactionLock::acquire('practice-proposals:'.app(TenantContext::class)->getTenantId());

            $year = now()->format('Y');
            $last = PracticeFeeProposal::query()->where('number', 'like', "FP-{$year}-%")->max('number');
            $number = sprintf('FP-%s-%04d', $year, $last === null ? 1 : ((int) substr((string) $last, -4)) + 1);

            $proposal = new PracticeFeeProposal;
            $proposal->fill([...$this->fields($data), 'number' => $number, 'status' => 'draft']);
            $proposal->save();

            return $proposal;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PracticeFeeProposal $proposal, array $data): PracticeFeeProposal
    {
        $this->assertStatus($proposal, ['draft'], 'Only a draft proposal can be changed.');
        $current = $proposal->only(['client_id', 'title', 'scope', 'fee_type', 'hourly_rate', 'estimated_hours', 'fixed_fee', 'valid_until']);
        $proposal->fill($this->fields([...$current, ...$data]));
        $proposal->save();

        return $proposal;
    }

    public function send(PracticeFeeProposal $proposal): PracticeFeeProposal
    {
        $this->assertStatus($proposal, ['draft'], 'Only a draft proposal can be sent.');
        $proposal->forceFill(['status' => 'sent', 'sent_at' => now()])->save();

        return $proposal;
    }

    /**
     * Marks as expired every sent proposal whose validity date is before today. Returns how many were expired.
     */
    public function expireOverdue(): int
    {
        $today = app(TenantClock::class)->today()->toDateString();

        return PracticeFeeProposal::query()->where('status', 'sent')->whereNotNull('valid_until')->whereDate('valid_until', '<', $today)
            ->update(['status' => 'expired', 'expired_at' => now()]);
    }

    /**
     * The proposal as a PDF document.
     */
    public function pdf(PracticeFeeProposal $proposal): string
    {
        $proposal->loadMissing('client');
        $client = $proposal->client;

        return app(PdfGenerator::class)->render('practice::pdf.fee_proposal', [
            'tenant' => app(TenantContext::class)->getTenant(),
            'proposal' => $proposal,
            'clientName' => $client === null ? '' : (string) ($client->company_name ?: trim($client->first_name.' '.$client->last_name)),
        ]);
    }

    public function decline(PracticeFeeProposal $proposal, ?string $notes): PracticeFeeProposal
    {
        $this->assertStatus($proposal, ['sent'], 'Only a sent proposal can be declined.');
        $proposal->forceFill(['status' => 'declined', 'decided_at' => now(), 'decision_notes' => $notes])->save();

        return $proposal;
    }

    /**
     * Accepts the proposal and opens the matter for the client.
     */
    public function accept(PracticeFeeProposal $proposal, string $conflictCheckId, ?string $overrideReason): PracticeFeeProposal
    {
        return DB::transaction(function () use ($proposal, $conflictCheckId, $overrideReason): PracticeFeeProposal {
            $locked = PracticeFeeProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $this->assertStatus($locked, ['sent'], 'Only a sent proposal can be accepted.');

            if ($locked->valid_until !== null && $locked->valid_until->endOfDay()->isPast()) {
                throw new BusinessRuleException('The proposal is expired.');
            }

            $check = PracticeConflictCheck::query()->findOrFail($conflictCheckId);

            if ($check->contact_id !== null && (string) $check->contact_id !== (string) $locked->client_id) {
                throw new BusinessRuleException('The conflicts check is about another party.');
            }

            if ($check->result === 'conflict' && ($overrideReason === null || trim($overrideReason) === '')) {
                throw new BusinessRuleException('The conflicts check found a conflict: a reason for proceeding is required.');
            }

            $case = LegalCase::create([
                'client_id' => $locked->client_id,
                'case_number' => $this->nextCaseNumber(),
                'title' => $locked->title,
                'description' => $locked->scope,
                'status' => 'open',
                'jurisdiction' => app(TenantContext::class)->getTenant()?->country === 'CH' ? 'CH' : 'IT',
            ]);

            $party = new PracticeCaseParty;
            $party->fill(['legal_case_id' => $case->id, 'contact_id' => $locked->client_id, 'name' => $check->subject_name, 'role' => 'client']);
            $party->save();

            $locked->forceFill([
                'status' => 'accepted',
                'decided_at' => now(),
                'conflict_check_id' => $check->id,
                'legal_case_id' => $case->id,
                'decision_notes' => $check->result === 'conflict' ? $overrideReason : null,
            ])->save();

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        $type = $data['fee_type'];
        $total = $type === 'hourly'
            ? Decimal::round2(Decimal::mul((string) ($data['hourly_rate'] ?? '0'), (string) ($data['estimated_hours'] ?? '0')))
            : Decimal::round2((string) ($data['fixed_fee'] ?? '0'));

        return [
            ...array_intersect_key($data, array_flip(['client_id', 'title', 'scope', 'fee_type', 'valid_until'])),
            'hourly_rate' => $type === 'hourly' ? $data['hourly_rate'] ?? null : null,
            'estimated_hours' => $type === 'hourly' ? $data['estimated_hours'] ?? null : null,
            'fixed_fee' => $type === 'fixed' ? $data['fixed_fee'] ?? null : null,
            'estimated_total' => $total,
        ];
    }

    private function nextCaseNumber(): string
    {
        TransactionLock::acquire('practice-cases:'.app(TenantContext::class)->getTenantId());
        $year = now()->format('Y');
        $last = LegalCase::withTrashed()->where('case_number', 'like', "M-{$year}-%")->max('case_number');

        return sprintf('M-%s-%04d', $year, $last === null ? 1 : ((int) substr((string) $last, -4)) + 1);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertStatus(PracticeFeeProposal $proposal, array $allowed, string $message): void
    {
        if (! in_array($proposal->status, $allowed, true)) {
            throw new BusinessRuleException($message);
        }
    }
}
