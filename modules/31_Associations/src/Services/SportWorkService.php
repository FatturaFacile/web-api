<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Associations\Enums\MembershipStatus;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\Membership;
use Modules\Associations\Models\SportCollaborator;
use Modules\Associations\Models\SportCompensation;
use Modules\Associations\Models\SportContract;
use Modules\Associations\Support\CsvFile;
use Modules\Associations\Support\Money;

/**
 * Sports work: compensations with their contribution and withholding data, the yearly totals per person against the
 * thresholds of the year, the single book of the services, the export of the sports registry and the CSV for the
 * accountant. Every threshold is a legal parameter of the year; the service computes no contribution or tax itself.
 */
final class SportWorkService
{
    public function __construct(private readonly LegalParameterService $parameters) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordCompensation(array $data): SportCompensation
    {
        $collaborator = SportCollaborator::query()->findOrFail($data['collaborator_id']);

        if (($data['contract_id'] ?? null) !== null && SportContract::query()->whereKey($data['contract_id'])->where('collaborator_id', $collaborator->id)->doesntExist()) {
            throw new AssociationException(__('association_finance.sport_contract_mismatch'));
        }

        foreach (['gross_amount', 'contribution_base', 'contribution_amount', 'withholding_base', 'withholding_amount'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = Money::normalize((string) $data[$field]);
            }
        }

        return SportCompensation::create($data);
    }

    /**
     * Yearly totals per person and their position against the thresholds configured for the year.
     *
     * @return array{year: int, thresholds: array<string, array<string, mixed>>, people: list<array<string, mixed>>}
     */
    public function thresholds(int $year): array
    {
        $contribution = $this->parameters->value(LegalParameterService::SportContributionThreshold, $year);
        $tax = $this->parameters->value(LegalParameterService::SportTaxThreshold, $year);
        $warning = $this->parameters->value(LegalParameterService::SportWarningPercent, $year);
        $people = [];

        foreach ($this->compensations($year)->groupBy('collaborator_id') as $rows) {
            $collaborator = $rows->first()->collaborator;
            $gross = $rows->reduce(static fn (string $total, SportCompensation $row): string => Money::add($total, Money::normalize((string) $row->gross_amount)), '0.00');
            $people[] = [
                'collaborator_id' => $collaborator->id,
                'name' => $collaborator->member->displayName(),
                'gross' => $gross,
                'contributions' => $rows->reduce(static fn (string $total, SportCompensation $row): string => Money::add($total, Money::normalize((string) $row->contribution_amount)), '0.00'),
                'withholdings' => $rows->reduce(static fn (string $total, SportCompensation $row): string => Money::add($total, Money::normalize((string) $row->withholding_amount)), '0.00'),
                'contribution_threshold' => $this->position($gross, $contribution, $warning),
                'tax_threshold' => $this->position($gross, $tax, $warning),
            ];
        }

        usort($people, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

        return [
            'year' => $year,
            'thresholds' => [
                'contribution' => $this->parameters->describe(LegalParameterService::SportContributionThreshold, $year),
                'tax' => $this->parameters->describe(LegalParameterService::SportTaxThreshold, $year),
                'warning_percent' => $this->parameters->describe(LegalParameterService::SportWarningPercent, $year),
            ],
            'people' => $people,
        ];
    }

    /**
     * The single book of the services of the year: one row per compensation, month by month.
     *
     * @return array{headers: list<string>, rows: list<list<mixed>>}
     */
    public function singleBook(int $year): array
    {
        $rows = $this->compensations($year)->sortBy(static fn (SportCompensation $row): string => $row->period.$row->collaborator->member->displayName())
            ->map(static fn (SportCompensation $row): array => [
                $row->period,
                $row->collaborator->member->contact->last_name,
                $row->collaborator->member->contact->first_name,
                $row->collaborator->member->tax_code,
                $row->collaborator->role,
                $row->contract_id === null ? null : SportContract::query()->find($row->contract_id)?->kind,
                $row->hours === null ? null : (string) $row->hours,
                Money::normalize((string) $row->gross_amount),
                $row->contribution_amount === null ? null : Money::normalize((string) $row->contribution_amount),
                $row->withholding_amount === null ? null : Money::normalize((string) $row->withholding_amount),
                Money::sub(Money::sub(Money::normalize((string) $row->gross_amount), Money::normalize((string) $row->withholding_amount)), Money::normalize((string) ($row->contribution_amount ?? '0'))),
                $row->paid_on->toDateString(),
            ])->values()->all();

        return [
            'headers' => ['period', 'last_name', 'first_name', 'tax_code', 'role', 'contract_kind', 'hours', 'gross', 'contributions', 'withholding', 'net_paid', 'paid_on'],
            'rows' => $rows,
        ];
    }

    public function singleBookCsv(int $year): string
    {
        $book = $this->singleBook($year);

        return CsvFile::write($book['headers'], $book['rows']);
    }

    /**
     * Data for the accountant: every compensation with the bases and amounts of contributions and withholdings.
     */
    public function accountantCsv(int $year): string
    {
        $rows = $this->compensations($year)->map(static fn (SportCompensation $row): array => [
            $row->paid_on->toDateString(), $row->period, $row->collaborator->member->tax_code, $row->collaborator->member->displayName(),
            Money::normalize((string) $row->gross_amount),
            $row->contribution_base === null ? null : Money::normalize((string) $row->contribution_base),
            $row->contribution_amount === null ? null : Money::normalize((string) $row->contribution_amount),
            $row->withholding_base === null ? null : Money::normalize((string) $row->withholding_base),
            $row->withholding_amount === null ? null : Money::normalize((string) $row->withholding_amount),
            $row->payment_method,
        ]);

        return CsvFile::write(['paid_on', 'period', 'tax_code', 'name', 'gross', 'contribution_base', 'contributions', 'withholding_base', 'withholding', 'payment_method'], $rows);
    }

    /**
     * The people of the sports registry in a year: members with a membership in force and collaborators. The official
     * layout of the national register is not reproduced: this is the data to transfer to it.
     */
    public function registryCsv(int $year): string
    {
        $from = CarbonImmutable::create($year)->startOfYear()->toDateString();
        $to = CarbonImmutable::create($year)->endOfYear()->toDateString();
        $rows = [];

        foreach (Membership::query()->with(['member.contact', 'membershipType'])->where('status', MembershipStatus::Active)->whereDate('valid_from', '<=', $to)->whereDate('valid_to', '>=', $from)->get() as $membership) {
            $member = $membership->member;
            $rows[] = ['member', $member->contact->last_name, $member->contact->first_name, $member->tax_code, $member->birth_date, $membership->card_number, $membership->membershipType?->name, $membership->valid_from?->toDateString(), $membership->valid_to?->toDateString()];
        }

        foreach (SportCollaborator::query()->with('member.contact')->whereDate('starts_on', '<=', $to)->where(static fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $from))->get() as $collaborator) {
            $member = $collaborator->member;
            $rows[] = ['collaborator', $member->contact->last_name, $member->contact->first_name, $member->tax_code, $member->birth_date, null, $collaborator->role, $collaborator->starts_on?->toDateString(), $collaborator->ends_on?->toDateString()];
        }

        return CsvFile::write(['type', 'last_name', 'first_name', 'tax_code', 'birth_date', 'card_number', 'role_or_membership', 'from', 'to'], $rows);
    }

    /**
     * Collaborators whose insurance expires within the days given, or has expired.
     *
     * @return Collection<int, SportCollaborator>
     */
    public function insuranceExpiries(int $days): Collection
    {
        return SportCollaborator::query()->whereNotNull('insurance_expires_on')
            ->whereDate('insurance_expires_on', '<=', CarbonImmutable::today()->addDays($days)->toDateString())
            ->where(static fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', CarbonImmutable::today()->toDateString()))
            ->orderBy('insurance_expires_on')
            ->get();
    }

    /**
     * @return Collection<int, SportCompensation>
     */
    private function compensations(int $year): Collection
    {
        return SportCompensation::query()->with('collaborator.member.contact')->whereYear('paid_on', $year)->orderBy('paid_on')->get();
    }

    /**
     * @return array{threshold: ?string, status: string, remaining: ?string}
     */
    private function position(string $gross, ?string $threshold, ?string $warning): array
    {
        if ($threshold === null) {
            return ['threshold' => null, 'status' => 'not_configured', 'remaining' => null];
        }

        $limit = Money::normalize($threshold);
        $status = match (true) {
            Money::compare($gross, $limit) > 0 => 'exceeded',
            $warning !== null && Money::compare(bcmul($gross, '100', 2), bcmul($limit, $warning, 2)) >= 0 => 'warning',
            default => 'below',
        };

        return ['threshold' => $limit, 'status' => $status, 'remaining' => Money::sub($limit, $gross)];
    }
}
