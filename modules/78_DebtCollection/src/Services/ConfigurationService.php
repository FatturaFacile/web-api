<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\DebtCollection\Exceptions\DebtCollectionException;
use Modules\DebtCollection\Models\EnforcementOffice;
use Modules\DebtCollection\Models\InterestRate;
use Modules\DebtCollection\Models\Policy;
use Modules\DebtCollection\Models\Template;

/**
 * Configuration of the module: dunning policies with their levels, interest rate periods, text templates and the
 * Swiss enforcement offices. Seeded global rows (statutory rates, standard texts) are read-only: changing one writes a
 * row of the company that takes its place.
 */
final class ConfigurationService
{
    public function __construct(private readonly InterestRateResolver $rates, private readonly DefaultPolicyProvisioner $defaults, private readonly PolicyResolver $policyResolver) {}

    /**
     * @return Collection<int, Policy>
     */
    public function policies(): Collection
    {
        $this->defaults->ensure();

        return Policy::query()->with('levels')->orderBy('jurisdiction')->orderByDesc('is_default')->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function savePolicy(?Policy $policy, array $data): Policy
    {
        $levels = (array) $data['levels'];
        unset($data['levels']);

        $this->assertLevels($levels);

        return DB::transaction(function () use ($policy, $data, $levels): Policy {
            if (($data['is_default'] ?? false) === true) {
                Policy::query()->where('jurisdiction', $data['jurisdiction'])->when($policy !== null, fn ($query) => $query->whereKeyNot($policy->id))->update(['is_default' => false]);
            }

            $values = [
                'name' => $data['name'],
                'jurisdiction' => $data['jurisdiction'],
                'currency' => $data['currency'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'is_default' => (bool) ($data['is_default'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'min_overdue_amount' => $data['min_overdue_amount'] ?? 0,
                'interest_basis' => $data['interest_basis'] ?? InterestCalculator::Actual365,
                'contractual_rate_percent' => $data['contractual_rate_percent'] ?? null,
            ];

            if ($policy === null) {
                $policy = Policy::create($values);
            } else {
                $policy->update($values);
                $policy->levels()->delete();
            }

            foreach ($levels as $level) {
                $policy->levels()->create([
                    'level' => $level['level'],
                    'min_days_overdue' => $level['min_days_overdue'],
                    'wait_days' => $level['wait_days'] ?? 0,
                    'deadline_days' => $level['deadline_days'] ?? 10,
                    'fee_amount' => $level['fee_amount'] ?? 0,
                    'charge_interest' => (bool) ($level['charge_interest'] ?? false),
                    'channels' => $level['channels'] ?? [],
                    'legal_warning' => (bool) ($level['legal_warning'] ?? false),
                    'is_active' => (bool) ($level['is_active'] ?? true),
                ]);
            }

            $this->policyResolver->flush();

            return $policy->load('levels');
        });
    }

    public function deletePolicy(Policy $policy): void
    {
        $policy->levels()->delete();
        $policy->delete();
        $this->policyResolver->flush();
    }

    /**
     * @return Collection<int, InterestRate>
     */
    public function rates(?string $jurisdiction): Collection
    {
        return InterestRate::query()->when($jurisdiction !== null, fn ($query) => $query->where('jurisdiction', $jurisdiction))->orderBy('jurisdiction')->orderByDesc('effective_from')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveRate(?InterestRate $rate, array $data): InterestRate
    {
        if ($rate !== null && $rate->tenant_id === null) {
            throw new DebtCollectionException('The statutory rates are read-only; add a rate of your own.', 'global_readonly', 403);
        }

        $values = [
            'jurisdiction' => $data['jurisdiction'],
            'kind' => $data['kind'] ?? $this->rates->kindFor((string) $data['jurisdiction']),
            'base_rate_percent' => $data['base_rate_percent'],
            'markup_percent' => $data['markup_percent'] ?? 0,
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        $saved = $rate === null ? InterestRate::create($values) : tap($rate)->update($values);
        $this->rates->flush();

        return $saved->refresh();
    }

    public function deleteRate(InterestRate $rate): void
    {
        if ($rate->tenant_id === null) {
            throw new DebtCollectionException('The statutory rates are read-only.', 'global_readonly', 403);
        }

        $rate->delete();
        $this->rates->flush();
    }

    /**
     * @return Collection<int, Template>
     */
    public function templates(?string $code, ?string $locale): Collection
    {
        return Template::query()
            ->when($code !== null, fn ($query) => $query->where('code', $code))
            ->when($locale !== null, fn ($query) => $query->where('locale', $locale))
            ->where('code', '!=', 'labels')
            ->where('code', '!=', 'betreibung')
            ->orderBy('code')->orderBy('locale')->orderByRaw('case when tenant_id is null then 1 else 0 end')
            ->get();
    }

    /**
     * Saves the text of a template. A seeded global template is never changed: the company gets its own copy.
     *
     * @param  array{subject?: string|null, body: string}  $data
     */
    public function saveTemplate(Template $template, array $data): Template
    {
        if ($template->tenant_id !== null) {
            $template->update(['subject' => $data['subject'] ?? $template->subject, 'body' => $data['body']]);

            return $template->refresh();
        }

        return Template::create([
            'code' => $template->code,
            'locale' => $template->locale,
            'jurisdiction' => $template->jurisdiction,
            'subject' => $data['subject'] ?? $template->subject,
            'body' => $data['body'],
        ]);
    }

    /**
     * @return Collection<int, EnforcementOffice>
     */
    public function offices(): Collection
    {
        return EnforcementOffice::query()->orderBy('canton')->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveOffice(?EnforcementOffice $office, array $data): EnforcementOffice
    {
        if ($office === null) {
            return EnforcementOffice::create($data);
        }

        $office->update($data);

        return $office->refresh();
    }

    /**
     * Levels are 0-3 and, for a policy to make sense, the days overdue never go down from one level to the next.
     *
     * @param  list<array<string, mixed>>  $levels
     */
    private function assertLevels(array $levels): void
    {
        usort($levels, static fn (array $a, array $b): int => $a['level'] <=> $b['level']);
        $previous = null;

        foreach ($levels as $level) {
            if ($previous !== null && $level['min_days_overdue'] < $previous) {
                throw new DebtCollectionException('The days overdue must not decrease from one level to the next.', 'levels_not_ascending');
            }

            $previous = $level['min_days_overdue'];
        }
    }
}
