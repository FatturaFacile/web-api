<?php

declare(strict_types=1);

namespace Modules\Treasury\Services\F24;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Treasury\Models\F24Delegation;
use Modules\Treasury\Models\F24DelegationLine;

/**
 * Lifecycle of a Modello F24 delegation: draft (editable) -> validated (balanced, every rule satisfied) -> generated
 * (telematic file stored with its SHA-256, immutable) -> paid. Validation is repeated when the file is generated, so a
 * delegation whose reference data changed can never produce a file that was not checked.
 */
final readonly class F24DelegationService
{
    public function __construct(
        private F24DelegationValidator $validator,
        private F24TelematicFileBuilder $builder,
        private TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string, mixed>  $data  header fields and `lines`
     */
    public function create(array $data, string $userId): F24Delegation
    {
        return DB::transaction(function () use ($data, $userId): F24Delegation {
            $delegation = new F24Delegation($this->header($data) + [
                'tenant_id' => (string) $this->tenantContext->getTenantId(),
                'status' => F24Delegation::DRAFT,
                'created_by' => $userId,
            ]);
            $delegation->save();
            $this->replaceLines($delegation, (array) $data['lines']);

            return $delegation->load('lines');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException when the delegation is no longer a draft
     */
    public function update(F24Delegation $delegation, array $data): F24Delegation
    {
        $this->assertEditable($delegation);

        return DB::transaction(function () use ($delegation, $data): F24Delegation {
            $delegation->update($this->header($data));
            $this->replaceLines($delegation, (array) $data['lines']);

            return $delegation->refresh()->load('lines');
        });
    }

    /**
     * @throws ValidationException when the delegation is not a draft
     */
    public function delete(F24Delegation $delegation): void
    {
        $this->assertEditable($delegation);
        DB::transaction(function () use ($delegation): void {
            $delegation->lines()->delete();
            $delegation->delete();
        });
    }

    /**
     * Checks the delegation and, when it is clean, marks it validated.
     *
     * @return array{delegation: F24Delegation, issues: list<array{path: string, code: string, params: array<string, string|int>}>}
     */
    public function validate(F24Delegation $delegation, ?CarbonImmutable $today = null): array
    {
        $delegation->loadMissing('lines');
        $issues = $this->issues($delegation, $today);

        if ($delegation->status === F24Delegation::DRAFT || $delegation->status === F24Delegation::VALIDATED) {
            $delegation->update($issues === []
                ? ['status' => F24Delegation::VALIDATED, 'validated_at' => now()]
                : ['status' => F24Delegation::DRAFT, 'validated_at' => null]);
        }

        return ['delegation' => $delegation->refresh()->load('lines'), 'issues' => $issues];
    }

    /**
     * @throws ValidationException when the delegation does not pass validation
     */
    public function generate(F24Delegation $delegation, ?CarbonImmutable $now = null): F24Delegation
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($delegation, $now): F24Delegation {
            $delegation = F24Delegation::query()->lockForUpdate()->findOrFail($delegation->id)->load('lines');

            if ($delegation->status === F24Delegation::GENERATED || $delegation->status === F24Delegation::PAID) {
                return $delegation;
            }

            $issues = $this->issues($delegation, $now->startOfDay());

            if ($issues !== []) {
                throw $this->exception($issues);
            }

            $content = $this->builder->build($delegation, $delegation->lines, $now);
            $delegation->update([
                'status' => F24Delegation::GENERATED,
                'file_name' => 'F24_'.$delegation->taxpayer_fiscal_code.'_'.$delegation->payment_date->format('Ymd').'.txt',
                'file_content' => $content,
                'file_hash' => hash('sha256', $content),
                'generated_at' => $now,
            ]);

            return $delegation->refresh()->load('lines');
        });
    }

    /**
     * @throws ValidationException when no file has been generated yet
     */
    public function markPaid(F24Delegation $delegation): F24Delegation
    {
        if ($delegation->status !== F24Delegation::GENERATED) {
            throw ValidationException::withMessages(['status' => __('treasury_flows.f24.not_generated')]);
        }

        $delegation->update(['status' => F24Delegation::PAID]);

        return $delegation->refresh();
    }

    /**
     * @return list<array{path: string, code: string, params: array<string, string|int>}>
     */
    private function issues(F24Delegation $delegation, ?CarbonImmutable $today): array
    {
        return $this->validator->check([
            'taxpayer_fiscal_code' => (string) $delegation->taxpayer_fiscal_code,
            'taxpayer_name' => (string) $delegation->taxpayer_name,
            'payment_date' => $delegation->payment_date->toDateString(),
        ], $delegation->lines, $today);
    }

    /**
     * @param  list<array{path: string, code: string, params: array<string, string|int>}>  $issues
     */
    public function exception(array $issues): ValidationException
    {
        $messages = [];

        foreach ($issues as $issue) {
            $messages[$issue['path']][] = __('treasury_flows.f24.issue.'.$issue['code'], $issue['params']);
        }

        return ValidationException::withMessages($messages);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(array $data): array
    {
        $lines = collect((array) $data['lines']);
        $totals = $this->validator->totals($lines);

        return [
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'taxpayer_fiscal_code' => strtoupper(trim((string) $data['taxpayer_fiscal_code'])),
            'taxpayer_name' => trim((string) $data['taxpayer_name']),
            'taxpayer_first_name' => $data['taxpayer_first_name'] ?? null,
            'taxpayer_birth_date' => $data['taxpayer_birth_date'] ?? null,
            'taxpayer_birth_place' => $data['taxpayer_birth_place'] ?? null,
            'taxpayer_birth_province' => isset($data['taxpayer_birth_province']) ? strtoupper((string) $data['taxpayer_birth_province']) : null,
            'taxpayer_gender' => isset($data['taxpayer_gender']) ? strtoupper((string) $data['taxpayer_gender']) : null,
            'payment_date' => $data['payment_date'],
            'total_debit' => $totals['debit'],
            'total_credit' => $totals['credit'],
            'total_payable' => $totals['payable'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(F24Delegation $delegation, array $lines): void
    {
        $delegation->lines()->forceDelete();

        foreach ($lines as $position => $line) {
            F24DelegationLine::create([
                'tenant_id' => $delegation->tenant_id,
                'f24_delegation_id' => $delegation->id,
                'section' => (string) $line['section'],
                'tax_code' => strtoupper((string) $line['tax_code']),
                'institution_code' => isset($line['institution_code']) ? strtoupper((string) $line['institution_code']) : null,
                'reference_number' => $line['reference_number'] ?? null,
                'installment' => $line['installment'] ?? null,
                'reference_period' => $line['reference_period'] ?? null,
                'reference_year' => $line['reference_year'] ?? null,
                'debit_amount' => bcadd((string) ($line['debit_amount'] ?? '0'), '0', 2),
                'credit_amount' => bcadd((string) ($line['credit_amount'] ?? '0'), '0', 2),
                'position' => $position,
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertEditable(F24Delegation $delegation): void
    {
        if (! in_array($delegation->status, [F24Delegation::DRAFT, F24Delegation::VALIDATED], true)) {
            throw ValidationException::withMessages(['status' => __('treasury_flows.f24.not_editable')]);
        }
    }

    /**
     * @return Collection<int, F24Delegation>
     */
    public function list(?string $status = null): Collection
    {
        return F24Delegation::query()->when($status, fn ($query, string $value) => $query->where('status', $value))->latest('payment_date')->get();
    }
}
