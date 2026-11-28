<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;
use Modules\WithholdingRecipients\Domain\ValueObjects\FiscalCode;
use Modules\WithholdingRecipients\Domain\ValueObjects\Money;
use Modules\WithholdingRecipients\Models\AgentReducedWithholdingCert;
use Modules\WithholdingRecipients\Models\OccasionalInlNotification;
use Modules\WithholdingRecipients\Models\RecipientProfile;
use Modules\WithholdingRecipients\Models\RecipientSettlement;

/**
 * Read side of the recipients: the dashboard (filters, year-to-date earnings, active declarations, compliance alerts) and
 * the progress of a recipient against the statutory thresholds.
 */
final class RecipientInsightService
{
    public function __construct(private readonly InlNotificationService $inl) {}

    /**
     * @param  array<string, mixed>  $filters  regime, jurisdiction, cu_causal, search, year, only_alerts
     * @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function dashboard(array $filters, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $year = (int) ($filters['year'] ?? $today->year);

        $profiles = RecipientProfile::query()
            ->when($filters['regime'] ?? null, static fn (Builder $q, string $regime) => $q->where('regime', $regime))
            ->when($filters['jurisdiction'] ?? null, static fn (Builder $q, string $j) => $q->where('jurisdiction', $j))
            ->when($filters['search'] ?? null, static fn (Builder $q, string $term) => $q->where(static function (Builder $inner) use ($term): void {
                $like = '%'.mb_strtolower($term).'%';
                $inner->whereRaw('LOWER(display_name) LIKE ?', [$like])->orWhereRaw('LOWER(COALESCE(tax_code, \'\')) LIKE ?', [$like]);
            }))
            ->orderBy('display_name')
            ->limit(1000)
            ->get();

        $totals = RecipientSettlement::query()
            ->where('tax_year', $year)
            ->where('status', 'posted')
            ->select('recipient_profile_id', DB::raw('SUM(gross_amount) AS gross'), DB::raw('SUM(irpef_withholding + source_tax) AS withheld'), DB::raw('COUNT(*) AS payments'), DB::raw('SUM(avs_base) AS avs_base'))
            ->groupBy('recipient_profile_id')
            ->toBase()
            ->get()
            ->keyBy('recipient_profile_id');

        $rows = [];

        foreach ($profiles as $profile) {
            $causal = $profile->cu_causal ?? $profile->regimeEnum()->defaultCuCausal($profile->mandateEnum());

            if (($filters['cu_causal'] ?? null) !== null && $causal !== $filters['cu_causal']) {
                continue;
            }

            $row = $totals->get($profile->id);
            $alerts = $this->alerts($profile, $year, $today, Money::of($row->gross ?? 0));

            if (! empty($filters['only_alerts']) && $alerts === []) {
                continue;
            }

            $rows[] = [
                'id' => $profile->id,
                'display_name' => $profile->display_name,
                'regime' => $profile->regime,
                'jurisdiction' => $profile->jurisdiction,
                'cu_causal' => $causal,
                'currency' => $profile->regimeEnum()->currency(),
                'tax_code' => $profile->tax_code,
                'is_active' => (bool) $profile->is_active,
                'ytd_gross' => Money::of($row->gross ?? 0)->toDecimal(),
                'ytd_withheld' => Money::of($row->withheld ?? 0)->toDecimal(),
                'payments' => (int) ($row->payments ?? 0),
                'alerts' => $alerts,
                'thresholds' => $this->thresholds($profile, $year, Money::of($row->gross ?? 0)),
            ];
        }

        return ['rows' => $rows, 'summary' => $this->summary($rows, $year)];
    }

    /**
     * @return list<array{key: string, current: string, limit: string, percent: float}>
     */
    public function thresholdsFor(RecipientProfile $profile, int $year): array
    {
        $gross = Money::of(RecipientSettlement::query()->where('recipient_profile_id', $profile->id)->where('tax_year', $year)->where('status', 'posted')->sum('gross_amount'));

        return $this->thresholds($profile, $year, $gross);
    }

    /**
     * @return list<array{key: string, current: string, limit: string, percent: float}>
     */
    private function thresholds(RecipientProfile $profile, int $year, Money $companyGross): array
    {
        $third = (int) $profile->third_party_income_year === $year ? Money::of($profile->third_party_ytd_income) : Money::zero();
        $make = static function (string $key, Money $current, Money $limit): array {
            $percent = $limit->cents === 0 ? 0.0 : min(100.0, round($current->cents * 100 / $limit->cents, 2));

            return ['key' => $key, 'current' => $current->toDecimal(), 'limit' => $limit->toDecimal(), 'percent' => $percent];
        };

        return match ($profile->regimeEnum()) {
            RecipientRegime::OccasionalWorker => [$make('occasional_5000', $companyGross->plus($third), Money::of(config('withholding_recipients.occasional.inps_threshold')))],
            RecipientRegime::AmateurSports => [
                $make('sports_5000', $companyGross->plus($third), Money::of(config('withholding_recipients.amateur_sports.exempt_threshold'))),
                $make('sports_15000', $companyGross->plus($third), Money::of(config('withholding_recipients.amateur_sports.irpef_threshold'))),
            ],
            RecipientRegime::SwissDeMinimis, RecipientRegime::SwissForeignArtist => [$make('avs_2300', $companyGross, Money::of(config('withholding_recipients.swiss.de_minimis')))],
            RecipientRegime::Agent25Bis => $profile->enasarco_mandate === null ? [] : [$make('enasarco_ceiling', $companyGross, Money::of(config('withholding_recipients.agent.enasarco.ceiling.'.$profile->enasarco_mandate)))],
            RecipientRegime::AuthorRoyalty => [],
        };
    }

    /**
     * @return list<string>
     */
    private function alerts(RecipientProfile $profile, int $year, CarbonImmutable $today, Money $companyGross): array
    {
        $alerts = [];

        switch ($profile->regimeEnum()) {
            case RecipientRegime::Agent25Bis:
                $cert = AgentReducedWithholdingCert::query()->where('recipient_profile_id', $profile->id)->orderByDesc('valid_to')->first();

                if ($cert === null || $cert->valid_to->endOfDay()->lessThan($today)) {
                    $alerts[] = 'agent_certificate_missing';
                } elseif ($cert->valid_to->lessThanOrEqualTo($today->addDays(60))) {
                    $alerts[] = 'agent_certificate_expiring';
                }

                if ($profile->enasarco_mandate === null) {
                    $alerts[] = 'enasarco_mandate_missing';
                }

                break;
            case RecipientRegime::AuthorRoyalty:
                if ($profile->birth_date === null && FiscalCode::tryFrom($profile->tax_code) === null) {
                    $alerts[] = 'birth_date_missing';
                }

                break;
            case RecipientRegime::OccasionalWorker:
                if (! $this->inl->isCovered($profile, $today)) {
                    $alerts[] = 'inl_notification_missing';
                }

                if ($companyGross->plus((int) $profile->third_party_income_year === $year ? Money::of($profile->third_party_ytd_income) : Money::zero())->greaterThan(Money::of(config('withholding_recipients.occasional.inps_threshold')))) {
                    $alerts[] = 'occasional_threshold_reached';
                }

                break;
            case RecipientRegime::AmateurSports:
                if ($companyGross->plus((int) $profile->third_party_income_year === $year ? Money::of($profile->third_party_ytd_income) : Money::zero())->greaterThan(Money::of(config('withholding_recipients.amateur_sports.exempt_threshold')))) {
                    $alerts[] = 'sports_threshold_reached';
                }

                break;
            case RecipientRegime::SwissDeMinimis:
            case RecipientRegime::SwissForeignArtist:
                if (! $profile->is_cultural_sector && $companyGross->greaterThan(Money::of(config('withholding_recipients.swiss.de_minimis')))) {
                    $alerts[] = 'avs_de_minimis_exceeded';
                }

                break;
        }

        return $alerts;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(array $rows, int $year): array
    {
        $collection = new Collection($rows);

        return [
            'year' => $year,
            'recipients' => $collection->count(),
            'with_alerts' => $collection->filter(static fn (array $row): bool => $row['alerts'] !== [])->count(),
            'ytd_gross_eur' => Money::of($collection->where('currency', 'EUR')->sum(static fn (array $row): float => (float) $row['ytd_gross']))->toDecimal(),
            'ytd_gross_chf' => Money::of($collection->where('currency', 'CHF')->sum(static fn (array $row): float => (float) $row['ytd_gross']))->toDecimal(),
            'pending_inl' => $collection->filter(static fn (array $row): bool => in_array('inl_notification_missing', $row['alerts'], true))->count(),
        ];
    }

    /**
     * Number of draft INL communications waiting to be sent (badge of the settlement centre).
     */
    public function pendingInlCount(): int
    {
        return OccasionalInlNotification::query()->where('status', 'draft')->count();
    }
}
