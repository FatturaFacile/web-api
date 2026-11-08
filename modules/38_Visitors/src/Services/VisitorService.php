<?php

declare(strict_types=1);

namespace Modules\Visitors\Services;

use App\Models\User;
use App\Services\Tenancy\TenantClock;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantPreferences;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Modules\Visitors\Mail\VisitorArrivedMail;
use Modules\Visitors\Models\Visitor;
use Modules\Visitors\Models\VisitorKiosk;
use Modules\Visitors\Models\VisitorVisit;

/**
 * Expected visits, check-in with a badge, check-out, the list of who is on site and the erasure at the end of the
 * retention period.
 */
final readonly class VisitorService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(
        private TenantContext $tenantContext,
        private TenantPreferences $preferences,
        private TenantClock $clock,
    ) {}

    /**
     * @param  array{visitor_id?: string, visitor?: array<string, mixed>, host_user_id: string, expected_at: string, expected_until?: ?string, purpose?: ?string}  $data
     */
    public function expect(array $data): VisitorVisit
    {
        return DB::transaction(function () use ($data): VisitorVisit {
            $visitor = isset($data['visitor_id'])
                ? Visitor::query()->findOrFail($data['visitor_id'])
                : Visitor::query()->create($data['visitor'] ?? []);

            return VisitorVisit::query()->create([
                'visitor_id' => $visitor->id,
                'host_user_id' => $data['host_user_id'],
                'expected_at' => $data['expected_at'],
                'expected_until' => $data['expected_until'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'status' => VisitorVisit::Expected,
            ])->load(['visitor', 'host']);
        });
    }

    /**
     * A visitor who arrives without appointment, checked in at once.
     *
     * @param  array<string, mixed>  $visitor
     */
    public function walkIn(array $visitor, string $hostUserId, string $badgeNumber, ?string $purpose = null, ?VisitorKiosk $kiosk = null): VisitorVisit
    {
        return DB::transaction(function () use ($visitor, $hostUserId, $badgeNumber, $purpose, $kiosk): VisitorVisit {
            $visit = $this->expect(['visitor' => $visitor, 'host_user_id' => $hostUserId, 'expected_at' => CarbonImmutable::now()->toDateTimeString(), 'purpose' => $purpose]);

            return $this->checkIn($visit, $badgeNumber, $kiosk);
        });
    }

    /**
     * Hands a badge to an expected visitor and tells the host by mail. A badge is worn by one person at a time.
     */
    public function checkIn(VisitorVisit $visit, string $badgeNumber, ?VisitorKiosk $kiosk = null): VisitorVisit
    {
        $visit = DB::transaction(function () use ($visit, $badgeNumber, $kiosk): VisitorVisit {
            /** @var VisitorVisit $locked */
            $locked = VisitorVisit::query()->lockForUpdate()->findOrFail($visit->id);

            if ($locked->status !== VisitorVisit::Expected) {
                throw ValidationException::withMessages(['visit' => 'Only an expected visit can be checked in.']);
            }

            $badgeTaken = VisitorVisit::query()
                ->where('status', VisitorVisit::CheckedIn)
                ->where('badge_number', $badgeNumber)
                ->lockForUpdate()
                ->exists();

            if ($badgeTaken) {
                throw ValidationException::withMessages(['badge_number' => 'This badge is already worn by a visitor on site.']);
            }

            $now = CarbonImmutable::now();
            $locked->update([
                'status' => VisitorVisit::CheckedIn,
                'badge_number' => $badgeNumber,
                'checked_in_at' => $now,
                'kiosk_id' => $kiosk?->id,
            ]);
            Visitor::query()->whereKey($locked->visitor_id)->update(['last_visit_at' => $now]);

            return $locked->load(['visitor', 'host']);
        });

        $this->notifyHost($visit);

        return $visit;
    }

    /**
     * Check out.
     */
    public function checkOut(VisitorVisit $visit): VisitorVisit
    {
        return DB::transaction(function () use ($visit): VisitorVisit {
            /** @var VisitorVisit $locked */
            $locked = VisitorVisit::query()->lockForUpdate()->findOrFail($visit->id);

            if ($locked->status !== VisitorVisit::CheckedIn) {
                throw ValidationException::withMessages(['visit' => 'Only a visitor on site can be checked out.']);
            }

            $now = CarbonImmutable::now();
            $locked->update(['status' => VisitorVisit::CheckedOut, 'checked_out_at' => $now]);
            Visitor::query()->whereKey($locked->visitor_id)->update(['last_visit_at' => $now]);

            return $locked->load(['visitor', 'host']);
        });
    }

    /**
     * Checks out the visitor wearing a badge, for the kiosk at the exit.
     */
    public function checkOutByBadge(string $badgeNumber): VisitorVisit
    {
        $visit = VisitorVisit::query()->where('status', VisitorVisit::CheckedIn)->where('badge_number', $badgeNumber)->first();

        if ($visit === null) {
            throw ValidationException::withMessages(['badge_number' => 'No visitor on site wears this badge.']);
        }

        return $this->checkOut($visit);
    }

    /**
     * Cancel.
     */
    public function cancel(VisitorVisit $visit): VisitorVisit
    {
        return DB::transaction(function () use ($visit): VisitorVisit {
            /** @var VisitorVisit $locked */
            $locked = VisitorVisit::query()->lockForUpdate()->findOrFail($visit->id);

            if ($locked->status !== VisitorVisit::Expected) {
                throw ValidationException::withMessages(['visit' => 'Only an expected visit can be cancelled.']);
            }

            $locked->update(['status' => VisitorVisit::Cancelled]);

            return $locked->load(['visitor', 'host']);
        });
    }

    /**
     * Who is in the building now, oldest arrival first: the list reception prints for an evacuation.
     *
     * @return Collection<int, VisitorVisit>
     */
    public function onSite(): Collection
    {
        return VisitorVisit::query()->with(['visitor', 'host'])->where('status', VisitorVisit::CheckedIn)->orderBy('checked_in_at')->get();
    }

    /**
     * Retention period of the active company, in days.
     */
    public function retentionDays(): int
    {
        $configured = $this->preferences->get('visitors', 'retention_days');

        return is_numeric($configured) ? (int) $configured : (int) config('visitors.retention_days');
    }

    /**
     * Erases the visits that ended before the retention period, and the visitors left without visits whose last visit is
     * also older. A visitor on site or still expected is never erased.
     *
     * @return array{visits: int, visitors: int}
     */
    public function purgeExpired(): array
    {
        $cutoff = CarbonImmutable::now()->subDays($this->retentionDays());

        return DB::transaction(function () use ($cutoff): array {
            $visits = VisitorVisit::query()
                ->where(static fn ($query) => $query
                    ->where(static fn ($ended) => $ended->where('status', VisitorVisit::CheckedOut)->where('checked_out_at', '<', $cutoff))
                    ->orWhere(static fn ($never) => $never->whereIn('status', [VisitorVisit::Cancelled, VisitorVisit::Expected])->where('expected_at', '<', $cutoff)))
                ->delete();

            $visitors = Visitor::query()
                ->whereDoesntHave('visits')
                ->where(static fn ($query) => $query->where('last_visit_at', '<', $cutoff)->orWhere(static fn ($never) => $never->whereNull('last_visit_at')->where('created_at', '<', $cutoff)))
                ->delete();

            return ['visits' => (int) $visits, 'visitors' => (int) $visitors];
        });
    }

    private function notifyHost(VisitorVisit $visit): void
    {
        /** @var ?User $host */
        $host = $visit->host;

        if ($host === null || $host->email === null || $host->email === '') {
            return;
        }

        Mail::to($host->email)
            ->locale($host->locale ?? 'it')
            ->queue(new VisitorArrivedMail(
                $visit->visitor->fullName(),
                $visit->visitor->company_name,
                (string) $visit->badge_number,
                (string) ($this->tenantContext->getTenant()?->name ?? ''),
                CarbonImmutable::instance($visit->checked_in_at ?? CarbonImmutable::now())->setTimezone($this->clock->timezone())->format('H:i'),
            ));

        $visit->forceFill(['host_notified_at' => CarbonImmutable::now()])->save();
    }
}
